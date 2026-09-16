<?php

namespace App\Services;

use App\Models\Slot;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SlotService
{
    /**
     * Bloquea una franja en cascada: cambia status a bloqueada y elimina
     * todas las reservas confirmadas con week_start >= semana actual.
     * Usa lockForUpdate() sobre el slot al inicio de la transacción para
     * serializar correctamente contra creaciones concurrentes (igual que ReservationService).
     */
    public function block(Slot $slot): Slot
    {
        return DB::transaction(function () use ($slot) {
            // Lock pesimista para serializar contra POST /api/reservations concurrentes
            $lockedSlot = Slot::where('id', $slot->id)->lockForUpdate()->firstOrFail();

            $lockedSlot->update(['status' => 'bloqueada']);

            $currentWeekStart = Carbon::now('Europe/Madrid')->startOfWeek(Carbon::MONDAY)->toDateString();

            // Hard DELETE solo futuras y actuales, no pasadas
            DB::table('reservations')
                ->where('slot_id', $lockedSlot->id)
                ->where('week_start', '>=', $currentWeekStart)
                ->delete();

            return $lockedSlot->fresh();
        });
    }

    public function unblock(Slot $slot): Slot
    {
        return DB::transaction(function () use ($slot) {
            $lockedSlot = Slot::where('id', $slot->id)->lockForUpdate()->firstOrFail();
            $lockedSlot->update(['status' => 'abierta']);
            return $lockedSlot->fresh();
        });
    }

    /**
     * Actualiza una franja con validación de aforo vigente (FR-011).
     * Usa lockForUpdate() para serializar contra creaciones concurrentes.
     * Rechaza si newCapacity < max ocupación vigente (week_start >= semana actual).
     */
    public function update(Slot $slot, array $data): Slot
    {
        return DB::transaction(function () use ($slot, $data) {
            $lockedSlot = Slot::where('id', $slot->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('capacity', $data)) {
                $newCapacity = (int) $data['capacity'];
                $currentWeekStart = Carbon::now('Europe/Madrid')->startOfWeek(Carbon::MONDAY)->toDateString();

                $counts = DB::table('reservations')
                    ->where('slot_id', $lockedSlot->id)
                    ->where('week_start', '>=', $currentWeekStart)
                    ->groupBy('week_start')
                    ->selectRaw('week_start, COUNT(*) as cnt')
                    ->pluck('cnt');

                $maxOccupation = $counts->max() ?? 0;

                if ($newCapacity < $maxOccupation) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'capacity' => ["El aforo no puede ser inferior a las reservas existentes ($maxOccupation). Elimine reservas primero."],
                    ]);
                }
            }

            $lockedSlot->update($data);

            return $lockedSlot->fresh();
        });
    }
}
