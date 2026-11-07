<?php

namespace App\Providers;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::define('permission', static function (GenericUser $user, string $permission): bool {
            $granted = $user->permissions;
            return is_array($granted) && in_array($permission, $granted, true);
        });

        Gate::define('role', static function (GenericUser $user, string $role): bool {
            $roles = $user->roles;
            return is_array($roles) && in_array($role, $roles, true);
        });
    }
}
