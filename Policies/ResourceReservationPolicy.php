<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Policies;

use App\Models\User;
use Filament\Facades\Filament;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;

class ResourceReservationPolicy
{
    /**
     * Admin bypass: admins (users with a panel role) can do anything.
     */
    public function before(User $user, string $ability): ?bool
    {
        $panel = Filament::getPanel('admin', isStrict: false);

        if ($panel !== null && $user->canAccessPanel($panel)) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether the user can view the reservation.
     */
    public function view(User $user, ResourceReservation $reservation): bool
    {
        return $reservation->user_id === $user->id;
    }

    /**
     * Determine whether the user can cancel the reservation.
     * Status eligibility is enforced in the service layer; this only checks identity.
     */
    public function cancel(User $user, ResourceReservation $reservation): bool
    {
        return $reservation->user_id === $user->id;
    }

    /**
     * Determine whether the user can extend the reservation.
     */
    public function extend(User $user, ResourceReservation $reservation): bool
    {
        return $reservation->user_id === $user->id;
    }

    /**
     * Determine whether the user can view any reservations (admin list endpoints).
     */
    public function viewAny(User $user): bool
    {
        $panel = Filament::getPanel('admin', isStrict: false);

        return $panel !== null && $user->canAccessPanel($panel);
    }
}
