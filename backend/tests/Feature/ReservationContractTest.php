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

class ReservationContractTest extends TestCase
{
    use RefreshDatabase;

    private function monday(): string { return Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString(); }

    public function test_contract_post_reservations_respuestas_201_409_422(): void
    {
        $week = $this->monday();
        $cliente = User::create(['name' => 'c', 'email' => 'c@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $slotOk = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $slotFull = Slot::create(['day_of_week' => 1, 'start_time' => '09:00:00', 'capacity' => 1, 'status' => 'abierta']);
        $other = User::create(['name' => 'o', 'email' => 'o@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        Reservation::create(['user_id' => $other->id, 'slot_id' => $slotFull->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($cliente);

        $r201 = $this->postJson('/api/reservations', ['slot_id' => $slotOk->id, 'week_start' => $week]);
        $r201->assertStatus(201);
        $r201->assertJsonStructure(['data' => ['id', 'user_id', 'slot_id', 'week_start']]);

        $r409 = $this->postJson('/api/reservations', ['slot_id' => $slotFull->id, 'week_start' => $week]);
        $r409->assertStatus(409);
        $this->assertStringContainsString('Franja completa', $r409->json('message'));

        $cliente2 = User::create(['name' => 'l', 'email' => 'l@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 1]);
        $slotA = Slot::create(['day_of_week' => 2, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $slotB = Slot::create(['day_of_week' => 2, 'start_time' => '09:00:00', 'capacity' => 4, 'status' => 'abierta']);
        Reservation::create(['user_id' => $cliente2->id, 'slot_id' => $slotA->id, 'week_start' => $week, 'status' => 'confirmada']);
        Sanctum::actingAs($cliente2);
        $r422 = $this->postJson('/api/reservations', ['slot_id' => $slotB->id, 'week_start' => $week]);
        $r422->assertStatus(422);
        $this->assertStringContainsString('Límite semanal', $r422->json('message'));
    }

    public function test_contract_admin_bypass_no_409_ni_422(): void
    {
        $week = $this->monday();
        $slotFull = Slot::create(['day_of_week' => 3, 'start_time' => '08:00:00', 'capacity' => 1, 'status' => 'abierta']);
        $other = User::create(['name' => 'o2', 'email' => 'o2@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        Reservation::create(['user_id' => $other->id, 'slot_id' => $slotFull->id, 'week_start' => $week, 'status' => 'confirmada']);

        $admin = User::create(['name' => 'admin', 'email' => 'admin@test.test', 'password' => Hash::make('password'), 'role' => 'administrador', 'weekly_hours' => 0]);
        $clienteTarget = User::create(['name' => 'target', 'email' => 'target@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 0]);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/reservations', ['slot_id' => $slotFull->id, 'week_start' => $week, 'user_id' => $clienteTarget->id]);
        $response->assertStatus(201);
    }
}
