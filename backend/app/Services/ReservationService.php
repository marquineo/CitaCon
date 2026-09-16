<?php

namespace App\Services;

use App\Models\Slot;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReservationService
{
    /**
     * Crea una reserva con validaciones de aforo y cupo, con bypass para admin (FR-013).
     *
     * @param int $slotId
     * @param string $weekStart Y-m-d lunes ISO
     * @param User $authUser Usuario autenticado (quien hace la petición)
     * @param int|null $targetUserId Usuario destino (solo si admin)
     * @return Reservation
     *
     * @throws HttpException 409 franja completa/bloqueada
     * @throws ValidationException 422 cupo/límite o duplicado (ya reservada)
     */
    public function create(int $slotId, string $weekStart, User $authUser, ?int $targetUserId = null): Reservation
    {
        // Determinar usuario efectivo: cliente siempre es él mismo, admin puede especificar otro
        $effectiveUserId = $authUser->id;
        $isAdmin = $authUser->role === 'administrador';

        if ($isAdmin && $targetUserId !== null) {
            $effectiveUserId = $targetUserId;
        }
        // Si es cliente e intenta enviar user_id, se ignora (ya está en $effectiveUserId = auth id)
        $targetUser = User::findOrFail($effectiveUserId);

        return DB::transaction(function () use ($slotId, $weekStart, $authUser, $targetUser, $isAdmin) {
            // Bloqueo pesimista sobre la fila de la franja (plantilla) para serializar aforo
            $slot = Slot::where('id', $slotId)->lockForUpdate()->firstOrFail();

            // Validar franja abierta (aplica a ambos roles; admin debe desbloquear primero)
            if ($slot->status === 'bloqueada') {
                throw new HttpException(409, 'Franja no disponible / bloqueada.');
            }

            // Validar franja no en el pasado: week_start + start_time > now
            $slotDateTime = \Carbon\Carbon::parse($weekStart . ' ' . $slot->start_time, 'Europe/Madrid');
            if ($slotDateTime->isPast()) {
                throw ValidationException::withMessages([
                    'week_start' => ['No se puede reservar una franja ya iniciada/pasada.'],
                ]);
            }

            // Validaciones de aforo y cupo SOLO si no es admin (FR-013 excepción)
            if (!$isAdmin) {
                $count = Reservation::where('slot_id', $slot->id)
                    ->where('week_start', $weekStart)
                    ->count();
                if ($count >= $slot->capacity) {
                    throw new HttpException(409, 'Franja completa: aforo máximo alcanzado');
                }

                $used = Reservation::where('user_id', $targetUser->id)
                    ->where('week_start', $weekStart)
                    ->count();
                if ($used >= $targetUser->weekly_hours) {
                    throw ValidationException::withMessages([
                        'weekly_hours' => ["Límite semanal alcanzado: tienes asignadas {$targetUser->weekly_hours}h y ya has reservado {$used}h esta semana."],
                    ]);
                }

            }

            // Crear reserva (UNIQUE user_id+slot_id+week_start como red de seguridad)
            try {
                return Reservation::create([
                    'user_id' => $targetUser->id,
                    'slot_id' => $slot->id,
                    'week_start' => $weekStart,
                    'status' => 'confirmada',
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Duplicado por UNIQUE (mismo usuario, misma franja, misma semana) — validación 422, no aforo 409
                if (str_contains($e->getMessage(), 'reservations_user_slot_week_unique') || $e->getCode() === '23000') {
                    throw ValidationException::withMessages([
                        'slot_id' => ['Ya tienes una reserva confirmada para esta franja en esta semana.'],
                    ]);
                }
                throw $e;
            }
        });
    }
}
