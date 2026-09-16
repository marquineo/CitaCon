<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Reservation;

/**
 * Solo autorización: ¿puede este usuario actuar sobre esta reserva?
 * Propietario o administrador. Sin lógica de aforo/cupo (vive en ReservationService T017).
 */
class ReservationPolicy
{
    public function view(User $user, Reservation $reservation): bool
    {
        return $user->id === $reservation->user_id || $user->isAdmin();
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isCliente() || $user->isAdmin();
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $user->id === $reservation->user_id || $user->isAdmin();
    }
}
