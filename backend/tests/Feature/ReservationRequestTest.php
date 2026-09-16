<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

/**
 * T019: Tests para ReservationRequest — validación week_start lunes ISO y franja futura.
 * Nota: La validación de "lunes" vive en ReservationRequest (withValidator isMonday),
 *       la validación de "franja ya pasada" vive realmente en ReservationService
 *       (Carbon::parse(week_start + start_time)->isPast()), no en Request.
 *       Este test verifica el comportamiento observable vía endpoint, independientemente
 *       de dónde resida la lógica, y deja constancia de la ubicación real.
 */
class ReservationRequestTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email, int $weeklyHours = 5): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'cliente',
            'weekly_hours' => $weeklyHours,
        ]);
    }

    private function makeSlot(int $day = 1, string $time = '08:00:00', int $capacity = 4, string $status = 'abierta'): Slot
    {
        return Slot::create([
            'day_of_week' => $day,
            'start_time' => $time,
            'capacity' => $capacity,
            'status' => $status,
        ]);
    }

    public function test_week_start_no_lunes_rechaza_con_422(): void
    {
        $cliente = $this->makeUser('lunes@test.test', 5);
        $slot = $this->makeSlot(1, '08:00:00');

        // Martes de la próxima semana (no lunes) — debe fallar validación isMonday en ReservationRequest
        $tuesday = Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->addDays(1)->toDateString();
        $this->assertFalse(Carbon::parse($tuesday)->isMonday(), 'Sanity: martes no es lunes');

        Sanctum::actingAs($cliente);

        $response = $this->postJson('/api/reservations', [
            'slot_id' => $slot->id,
            'week_start' => $tuesday,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['week_start']);
        $this->assertStringContainsString('lunes', strtolower($response->json('message') . json_encode($response->json('errors'))));
        $this->assertStringContainsString('semana ISO', $response->json('message') . json_encode($response->json('errors')));
    }

    public function test_week_start_franja_pasada_rechaza_con_422(): void
    {
        // Nota: Esta validación vive realmente en ReservationService (isPast sobre week_start + start_time),
        // no en ReservationRequest. El test la verifica vía endpoint igualmente.
        $cliente = $this->makeUser('pasada@test.test', 5);
        $slot = $this->makeSlot(1, '08:00:00'); // Lunes 08:00

        // Lunes de la semana pasada — definitivamente en el pasado
        $pastMonday = Carbon::now('Europe/Madrid')->subWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
        $slotDateTime = Carbon::parse($pastMonday . ' ' . $slot->start_time, 'Europe/Madrid');
        $this->assertTrue($slotDateTime->isPast(), 'Sanity: franja pasada debe ser isPast()');

        Sanctum::actingAs($cliente);

        $response = $this->postJson('/api/reservations', [
            'slot_id' => $slot->id,
            'week_start' => $pastMonday,
        ]);

        $response->assertStatus(422);
        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('No se puede reservar una franja ya iniciada/pasada', $payload);
        $response->assertJsonValidationErrors(['week_start']);
    }
}
