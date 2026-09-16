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

class IsolationTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(): string
    {
        return Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public function test_listado_filtra_por_auth_id_cliente_solo_suyas(): void
    {
        $week = $this->nextMonday();
        $clienteA = User::create(['name' => 'A', 'email' => 'a@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $clienteB = User::create(['name' => 'B', 'email' => 'b@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);

        Reservation::create(['user_id' => $clienteA->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);
        Reservation::create(['user_id' => $clienteA->id, 'slot_id' => $slot->id, 'week_start' => Carbon::parse($week)->addWeek()->toDateString(), 'status' => 'confirmada']);
        Reservation::create(['user_id' => $clienteB->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($clienteA);
        $response = $this->getJson('/api/reservations');
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('user_id')->unique()->toArray();
        $this->assertCount(1, $ids);
        $this->assertEquals($clienteA->id, $ids[0]);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_detalle_ajeno_404_indistinguible(): void
    {
        $week = $this->nextMonday();
        $owner = User::create(['name' => 'owner', 'email' => 'owner@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $attacker = User::create(['name' => 'attacker', 'email' => 'attacker@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '09:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $reservation = Reservation::create(['user_id' => $owner->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($attacker);
        $response = $this->getJson("/api/reservations/{$reservation->id}");
        $response->assertStatus(404);
        $response->assertJson(['message' => 'No encontrado.']);
        $this->assertStringNotContainsString((string) $owner->id, json_encode($response->json()));
    }

    public function test_admin_puede_ver_detalle_ajeno_200(): void
    {
        $week = $this->nextMonday();
        $owner = User::create(['name' => 'owner2', 'email' => 'owner2@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $admin = User::create(['name' => 'admin', 'email' => 'admin2@test.test', 'password' => Hash::make('password'), 'role' => 'administrador', 'weekly_hours' => 0]);
        $slot = Slot::create(['day_of_week' => 2, 'start_time' => '10:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $reservation = Reservation::create(['user_id' => $owner->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/reservations/{$reservation->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $reservation->id);
    }

    public function test_slots_no_exponen_lista_de_usuarios(): void
    {
        $week = $this->nextMonday();
        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $u1 = User::create(['name' => 'u1', 'email' => 'u1@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $u2 = User::create(['name' => 'u2', 'email' => 'u2@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        Reservation::create(['user_id' => $u1->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);
        Reservation::create(['user_id' => $u2->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($u1);
        $response = $this->getJson("/api/slots?week_start=$week");
        $response->assertStatus(200);
        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString($u1->email, $payload);
        $this->assertStringNotContainsString($u2->email, $payload);
        // Pero sí debe exponer occupation
        $slotData = collect($response->json('data'))->firstWhere('id', $slot->id);
        $this->assertEquals(2, $slotData['occupation']);
    }
}
