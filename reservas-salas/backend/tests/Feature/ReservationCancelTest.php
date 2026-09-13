<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Slot;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class ReservationCancelTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email, string $role, int $weeklyHours): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'weekly_hours' => $weeklyHours,
        ]);
    }

    private function makeSlot(int $day, string $time, int $capacity = 4, string $status = 'abierta'): Slot
    {
        return Slot::create([
            'day_of_week' => $day,
            'start_time' => $time,
            'capacity' => $capacity,
            'status' => $status,
        ]);
    }

    private function nextMonday(): string
    {
        return Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    private function pastMonday(): string
    {
        return Carbon::now('Europe/Madrid')->subWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public function test_cliente_puede_cancelar_propia_reserva_200_y_libera_cupo_y_unique(): void
    {
        $cliente = $this->makeUser('owner@test.test', 'cliente', 3);
        $slot = $this->makeSlot(1, '08:00:00', 4, 'abierta');
        $week = $this->nextMonday();

        $reservation = Reservation::create([
            'user_id' => $cliente->id,
            'slot_id' => $slot->id,
            'week_start' => $week,
            'status' => 'confirmada',
        ]);

        Sanctum::actingAs($cliente);

        // Cancel propia
        $resp = $this->deleteJson("/api/reservations/{$reservation->id}");
        $resp->assertStatus(200);
        $this->assertDatabaseMissing('reservations', ['id' => $reservation->id]);
        $this->assertEquals(0, Reservation::where('user_id', $cliente->id)->where('week_start', $week)->count());

        // Hard DELETE libera UNIQUE: puede volver a reservar misma franja+semana
        $r2 = $this->postJson('/api/reservations', ['slot_id' => $slot->id, 'week_start' => $week]);
        $r2->assertStatus(201);
        $this->assertEquals(1, Reservation::where('user_id', $cliente->id)->where('slot_id', $slot->id)->where('week_start', $week)->count());
    }

    public function test_cliente_no_puede_cancelar_reserva_ajena_404(): void
    {
        $owner = $this->makeUser('owner2@test.test', 'cliente', 3);
        $attacker = $this->makeUser('attacker@test.test', 'cliente', 3);
        $slot = $this->makeSlot(1, '09:00:00', 4);
        $week = $this->nextMonday();

        $reservation = Reservation::create([
            'user_id' => $owner->id,
            'slot_id' => $slot->id,
            'week_start' => $week,
            'status' => 'confirmada',
        ]);

        Sanctum::actingAs($attacker);

        $resp = $this->deleteJson("/api/reservations/{$reservation->id}");
        // 404 indistinguible para no filtrar existencia (FR-008)
        $resp->assertStatus(404);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
    }

    public function test_admin_puede_cancelar_reserva_ajena_200(): void
    {
        $owner = $this->makeUser('owner3@test.test', 'cliente', 3);
        $admin = $this->makeUser('admin@test.test', 'administrador', 0);
        $slot = $this->makeSlot(1, '10:00:00', 4);
        $week = $this->nextMonday();

        $reservation = Reservation::create([
            'user_id' => $owner->id,
            'slot_id' => $slot->id,
            'week_start' => $week,
            'status' => 'confirmada',
        ]);

        Sanctum::actingAs($admin);

        $resp = $this->deleteJson("/api/reservations/{$reservation->id}");
        $resp->assertStatus(200);
        $this->assertDatabaseMissing('reservations', ['id' => $reservation->id]);
    }

    public function test_cancelar_franja_pasada_rechaza_con_422(): void
    {
        $cliente = $this->makeUser('past@test.test', 'cliente', 3);
        $slot = $this->makeSlot(1, '08:00:00', 4, 'abierta');
        $pastWeek = $this->pastMonday();

        $reservation = Reservation::create([
            'user_id' => $cliente->id,
            'slot_id' => $slot->id,
            'week_start' => $pastWeek,
            'status' => 'confirmada',
        ]);

        Sanctum::actingAs($cliente);

        $resp = $this->deleteJson("/api/reservations/{$reservation->id}");
        $resp->assertStatus(422);
        $payload = json_encode($resp->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('No se puede cancelar', $payload);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
    }
}
