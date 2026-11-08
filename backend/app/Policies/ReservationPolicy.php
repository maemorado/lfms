<?php

namespace App\Policies;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;

final class ReservationPolicy
{
    private function can(GenericUser $user, string $permission): bool
    {
        return is_array($user->permissions) && in_array($permission, $user->permissions, true);
    }

    private function manages(GenericUser $user): bool
    {
        return $this->can($user, 'reservations.manage');
    }

    private function owns(GenericUser $user, Reservation $reservation): bool
    {
        return (string)$reservation->requester_id === (string)$user->getAuthIdentifier();
    }

    public function viewAny(GenericUser $user): bool
    {
        return $this->can($user, 'reservations.view');
    }

    public function view(GenericUser $user, Reservation $reservation): bool
    {
        return $this->can($user, 'reservations.view') && ($this->manages($user) || $this->owns($user, $reservation));
    }

    public function create(GenericUser $user): bool
    {
        return $this->can($user, 'reservations.create');
    }

    public function update(GenericUser $user, Reservation $reservation): bool
    {
        return $this->can($user, 'reservations.create')
            && $reservation->status === 'pending'
            && ($this->manages($user) || $this->owns($user, $reservation));
    }

    public function cancel(GenericUser $user, Reservation $reservation): bool
    {
        return $this->can($user, 'reservations.create')
            && ($this->manages($user) || $this->owns($user, $reservation))
            && in_array($reservation->status, ['pending', 'approved'], true)
            && CarbonImmutable::parse($reservation->start_at)->isFuture();
    }

    public function decide(GenericUser $user, Reservation $reservation): bool
    {
        return $this->manages($user) && $reservation->status === 'pending';
    }
}
