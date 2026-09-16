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

class QuotaAndSlotListTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(): string
    {
        return Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public function test_quota_devuelve_assigned_used_remaining_para_autenticado(): void
    {
        $week = $this->nextMonday();
        $cliente = User::create(['name' => 'c', 'email' => 'c@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $slot1 = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $slot2 = Slot::create(['day_of_week' => 1, 'start_time' => '09:00:00', 'capacity' => 4, 'status' => 'abierta']);
        Reservation::create(['user_id' => $cliente->id, 'slot_id' => $slot1->id, 'week_start' => $week, 'status' => 'confirmada']);
        Reservation::create(['user_id' => $cliente->id, 'slot_id' => $slot2->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($cliente);
        $response = $this->getJson("/api/users/me/quota?week_start=$week");
        $response->assertStatus(200);
        $response->assertJsonPath('data.assigned', 5);
        $response->assertJsonPath('data.used', 2);
        $response->assertJsonPath('data.remaining', 3);
        $response->assertJsonPath('data.week_start', $week);
    }

    public function test_quota_sin_week_start_usa_semana_actual(): void
    {
        $cliente = User::create(['name' => 'c2', 'email' => 'c2@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 3]);
        Sanctum::actingAs($cliente);
        $response = $this->getJson('/api/users/me/quota');
        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['assigned', 'used', 'remaining', 'week_start']]);
    }

    public function test_quota_no_acepta_user_id_de_otro_cliente(): void
    {
        $week = $this->nextMonday();
        $clienteA = User::create(['name' => 'a', 'email' => 'a@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $clienteB = User::create(['name' => 'b', 'email' => 'b@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 10]);
        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);
        Reservation::create(['user_id' => $clienteB->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);
        Reservation::create(['user_id' => $clienteB->id, 'slot_id' => $slot->id, 'week_start' => Carbon::parse($week)->addWeek()->toDateString(), 'status' => 'confirmada']);

        Sanctum::actingAs($clienteA);
        // Intentar ?user_id=B debe ser ignorado, siempre ve su propio quota
        $response = $this->getJson("/api/users/me/quota?week_start=$week&user_id={$clienteB->id}");
        $response->assertStatus(200);
        $this->assertEquals(5, $response->json('data.assigned'));
        $this->assertEquals(0, $response->json('data.used')); // A no tiene reservas esa semana
    }

    public function test_admin_quota_con_user_id_opcional(): void
    {
        $week = $this->nextMonday();
        $admin = User::create(['name' => 'admin', 'email' => 'admin@test.test', 'password' => Hash::make('password'), 'role' => 'administrador', 'weekly_hours' => 0]);
        $cliente = User::create(['name' => 'c', 'email' => 'c3@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 4]);
        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);
        Reservation::create(['user_id' => $cliente->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($admin);
        // Admin sin user_id ve su propio quota (0)
        $r1 = $this->getJson("/api/users/me/quota?week_start=$week");
        $r1->assertStatus(200);
        // Admin con ?user_id ve el del cliente
        $r2 = $this->getJson("/api/users/me/quota?week_start=$week&user_id={$cliente->id}");
        $r2->assertStatus(200);
        $r2->assertJsonPath('data.assigned', 4);
        $r2->assertJsonPath('data.used', 1);
    }

    public function test_slots_con_occupation_por_semana(): void
    {
        $week = $this->nextMonday();
        $week2 = Carbon::parse($week)->addWeek()->toDateString();
        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $u1 = User::create(['name' => 'u1', 'email' => 'u1@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $u2 = User::create(['name' => 'u2', 'email' => 'u2@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        Reservation::create(['user_id' => $u1->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);
        Reservation::create(['user_id' => $u2->id, 'slot_id' => $slot->id, 'week_start' => $week2, 'status' => 'confirmada']);

        Sanctum::actingAs($u1);
        $r1 = $this->getJson("/api/slots?week_start=$week");
        $r1->assertStatus(200);
        $slotData = collect($r1->json('data'))->firstWhere('id', $slot->id);
        $this->assertEquals(1, $slotData['occupation']);

        $r2 = $this->getJson("/api/slots?week_start=$week2");
        $slotData2 = collect($r2->json('data'))->firstWhere('id', $slot->id);
        $this->assertEquals(1, $slotData2['occupation']);

        // Sin week_start, no debe exponer occupation o debe ser 0
        $r3 = $this->getJson('/api/slots');
        $this->assertTrue($r3->status() === 200);
    }
}
