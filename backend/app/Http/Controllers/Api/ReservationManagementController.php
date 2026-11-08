<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CancelReservationRequest;
use App\Http\Requests\Api\ReservationDecisionRequest;
use App\Http\Requests\Api\ReservationIndexRequest;
use App\Http\Requests\Api\StoreReservationRequest;
use App\Http\Requests\Api\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Services\Reservations\ReservationManagementService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ReservationManagementController extends Controller
{
    public function __construct(private readonly ReservationManagementService $reservations) {}

    private function actorId(Request $request): string
    {
        return (string)$request->attributes->get('supabase_user')['id'];
    }

    private function isAdmin(Request $request): bool
    {
        $context = $request->attributes->get('lfms_authorization', []);
        return in_array('reservations.manage', $context['permissions'] ?? [], true);
    }

    private function model(array $attributes): Reservation
    {
        return (new Reservation())->forceFill($attributes);
    }

    public function index(ReservationIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Reservation::class);
        return ApiResponse::paginated(
            $this->reservations->list($request->validated(), $this->actorId($request), $this->isAdmin($request)),
            'Reservations retrieved successfully.',
        );
    }

    public function show(Request $request, string $reservationId): JsonResponse
    {
        $reservation = $this->reservations->find($reservationId, $this->actorId($request), $this->isAdmin($request));
        Gate::authorize('view', $this->model($reservation));
        return ApiResponse::success(['reservation' => new ReservationResource($reservation)], 'Reservation details retrieved successfully.');
    }

    public function store(StoreReservationRequest $request): JsonResponse
    {
        Gate::authorize('create', Reservation::class);
        $reservation = $this->reservations->create($this->actorId($request), $request->validated());
        return ApiResponse::success(['reservation' => new ReservationResource($reservation)], 'Reservation request submitted for approval.', 201);
    }

    public function update(UpdateReservationRequest $request, string $reservationId): JsonResponse
    {
        $actorId = $this->actorId($request);
        $isAdmin = $this->isAdmin($request);
        $existing = $this->reservations->find($reservationId, $actorId, $isAdmin);
        Gate::authorize('update', $this->model($existing));
        $reservation = $this->reservations->update($actorId, $reservationId, $request->validated(), $isAdmin);
        return ApiResponse::success(['reservation' => new ReservationResource($reservation)], 'Reservation updated successfully.');
    }

    public function cancel(CancelReservationRequest $request, string $reservationId): JsonResponse
    {
        $actorId = $this->actorId($request);
        $isAdmin = $this->isAdmin($request);
        $existing = $this->reservations->find($reservationId, $actorId, $isAdmin);
        Gate::authorize('cancel', $this->model($existing));
        $data = $request->validated();
        $reservation = $this->reservations->cancel($actorId, $reservationId, $data['cancellation_reason'], $isAdmin);
        return ApiResponse::success(['reservation' => new ReservationResource($reservation)], 'Reservation cancelled successfully.');
    }

    public function decide(ReservationDecisionRequest $request, string $reservationId): JsonResponse
    {
        $actorId = $this->actorId($request);
        $existing = $this->reservations->find($reservationId, $actorId, true);
        Gate::authorize('decide', $this->model($existing));
        $data = $request->validated();
        $reservation = $this->reservations->decide($actorId, $reservationId, $data['decision'], $data['remarks'] ?? null);
        return ApiResponse::success(['reservation' => new ReservationResource($reservation)], 'Reservation '.$data['decision'].' successfully.');
    }
}
