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

class ReservationQuotaCapacityTest extends TestCase
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

    private function makeSlot(int $day, string $time, int $capacity, string $status = 'abierta'): Slot
    {
        return Slot::create([
            'day_of_week' => $day,
            'start_time' => $time,
            'capacity' => $capacity,
            'status' => $status,
        ]);
    }

    private function monday(): string
    {
        // Usar próximo lunes para evitar "franja en el pasado" dentro de la semana actual
        return Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public function test_cliente_con_cupo_puede_reservar_franja_disponible(): void
    {
        $cliente = $this->makeUser('a@test.test', 'cliente', 3);
        $slot = $this->makeSlot(1, '08:00:00', 4, 'abierta');
        $week = $this->monday();

        Sanctum::actingAs($cliente);

        $response = $this->postJson('/api/reservations', [
            'slot_id' => $slot->id,
            'week_start' => $week,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.user_id', $cliente->id);
        $this->assertDatabaseHas('reservations', [
            'user_id' => $cliente->id,
            'slot_id' => $slot->id,
            'week_start' => $week,
        ]);
        $this->assertEquals(1, Reservation::where('user_id', $cliente->id)->where('week_start', $week)->count());
    }

    public function test_franja_completa_rechaza_con_409(): void
    {
        $slot = $this->makeSlot(1, '09:00:00', 2, 'abierta');
        $week = $this->monday();

        $c1 = $this->makeUser('c1@test.test', 'cliente', 5);
        $c2 = $this->makeUser('c2@test.test', 'cliente', 5);
        Reservation::create(['user_id' => $c1->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);
        Reservation::create(['user_id' => $c2->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);

        $cliente = $this->makeUser('c3@test.test', 'cliente', 5);
        Sanctum::actingAs($cliente);

        $response = $this->postJson('/api/reservations', [
            'slot_id' => $slot->id,
            'week_start' => $week,
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('Franja completa', $response->json('message'));
        $this->assertEquals(2, Reservation::where('slot_id', $slot->id)->where('week_start', $week)->count());
    }

    public function test_limite_semanal_rechaza_con_422(): void
    {
        $week = $this->monday();
        $cliente = $this->makeUser('limited@test.test', 'cliente', 2);
        $slot1 = $this->makeSlot(1, '08:00:00', 4);
        $slot2 = $this->makeSlot(1, '09:00:00', 4);
        $slot3 = $this->makeSlot(1, '10:00:00', 4);

        Reservation::create(['user_id' => $cliente->id, 'slot_id' => $slot1->id, 'week_start' => $week, 'status' => 'confirmada']);
        Reservation::create(['user_id' => $cliente->id, 'slot_id' => $slot2->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($cliente);

        $response = $this->postJson('/api/reservations', [
            'slot_id' => $slot3->id,
            'week_start' => $week,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Límite semanal', $response->json('message'));
    }

    public function test_cliente_0h_rechaza_con_422(): void
    {
        $cliente = $this->makeUser('cero@test.test', 'cliente', 0);
        $slot = $this->makeSlot(1, '08:00:00', 4);
        $week = $this->monday();

        Sanctum::actingAs($cliente);

        $response = $this->postJson('/api/reservations', [
            'slot_id' => $slot->id,
            'week_start' => $week,
        ]);

        $response->assertStatus(422);
    }

    public function test_franja_bloqueada_rechaza_con_409(): void
    {
        $cliente = $this->makeUser('blocked@test.test', 'cliente', 5);
        $slot = $this->makeSlot(2, '09:00:00', 4, 'bloqueada');
        $week = $this->monday();

        Sanctum::actingAs($cliente);

        $response = $this->postJson('/api/reservations', [
            'slot_id' => $slot->id,
            'week_start' => $week,
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('bloqueada', strtolower($response->json('message')));
    }

    public function test_reserva_duplicada_misma_franja_semana_rechaza_con_422(): void
    {
        $cliente = $this->makeUser('dup@test.test', 'cliente', 5);
        $slot = $this->makeSlot(1, '08:00:00', 4, 'abierta');
        $week = $this->monday();

        Sanctum::actingAs($cliente);

        $r1 = $this->postJson('/api/reservations', [
            'slot_id' => $slot->id,
            'week_start' => $week,
        ]);
        $r1->assertStatus(201);

        $r2 = $this->postJson('/api/reservations', [
            'slot_id' => $slot->id,
            'week_start' => $week,
        ]);
        $r2->assertStatus(422);
        // El mensaje debe estar en errors.slot_id o en message
        $payload = json_encode($r2->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Ya tienes una reserva confirmada para esta franja en esta semana', $payload);
        $r2->assertJsonValidationErrors(['slot_id']);

        $this->assertEquals(1, Reservation::where('user_id', $cliente->id)->where('slot_id', $slot->id)->where('week_start', $week)->count());
        $this->assertEquals(1, Reservation::where('slot_id', $slot->id)->where('week_start', $week)->count());
    }
}
