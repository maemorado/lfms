<?php

namespace App\Policies;

use App\Models\Facility;
use Illuminate\Auth\GenericUser;

final class FacilityPolicy
{
    private function can(GenericUser $user, string $permission): bool
    {
        return is_array($user->permissions) && in_array($permission, $user->permissions, true);
    }

    public function viewAny(GenericUser $user): bool
    {
        return $this->can($user, 'facilities.view');
    }

    public function view(GenericUser $user, Facility $facility): bool
    {
        return $this->can($user, 'facilities.view');
    }

    public function create(GenericUser $user): bool
    {
        return $this->can($user, 'facilities.manage');
    }

    public function update(GenericUser $user, Facility $facility): bool
    {
        return $this->can($user, 'facilities.manage');
    }
}
