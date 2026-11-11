<?php

namespace App\Http\Middleware;

use App\Services\Authorization\NeonAuthorizationService;
use App\Services\Security\NeonIdentityContext;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Loads verified authorization context, then commits before the controller.
 *
 * Use only for workflows that must coordinate a database transaction with an
 * external system (for example Supabase Storage). Those services establish a
 * fresh signed transaction identity around each Neon operation.
 */
final class LoadLfmsAuthorizationContextStateless
{
    public function __construct(
        private readonly NeonAuthorizationService $authorization,
        private readonly NeonIdentityContext $identity,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->attributes->get('supabase_user');
        $userId = is_array($identity) && is_string($identity['id'] ?? null) ? $identity['id'] : null;
        $email = is_array($identity) && is_string($identity['email'] ?? null) ? $identity['email'] : null;

        if (!is_string($userId) || !Str::isUuid($userId)) {
            return ApiResponse::error('Authentication is required.', 401, 'unauthenticated');
        }

        $context = DB::transaction(function () use ($userId, $email): array|Response|null {
            try {
                $this->identity->set($userId);
                $context = $this->authorization->resolve($userId);
                if ($context === null) {
                    return ApiResponse::error(
                        'Your LFMS account has no active profile or authorized role. Contact an administrator.',
                        403,
                        'role_not_assigned',
                    );
                }

                $this->authorization->syncAuthEmail($userId, $email);
                if (is_string($email) && $email !== '') {
                    $context['profile']['email'] = $email;
                }

                return $context;
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) === '23505') {
                    return ApiResponse::error(
                        'The email on your Auth account conflicts with another LFMS profile. Contact an administrator.',
                        409,
                        'profile_email_conflict',
                    );
                }

                return ApiResponse::error('Authorization data is temporarily unavailable.', 503, 'authorization_unavailable');
            } catch (Throwable) {
                return ApiResponse::error('Authorization data is temporarily unavailable.', 503, 'authorization_unavailable');
            }
        });

        if ($context instanceof Response) return $context;
        if (!is_array($context)) {
            return ApiResponse::error('Authorization data is temporarily unavailable.', 503, 'authorization_unavailable');
        }

        $request->attributes->set('lfms_authorization', $context);
        Auth::setUser(new GenericUser([
            'id' => $userId,
            'email' => $context['profile']['email'] ?? $email,
            'profile' => $context['profile'],
            'roles' => $context['roles'],
            'permissions' => $context['permissions'],
        ]));

        return $next($request);
    }
}
