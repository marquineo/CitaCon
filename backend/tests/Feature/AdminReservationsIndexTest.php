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

class AdminReservationsIndexTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(): string
    {
        return Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public function test_admin_sin_filtro_ve_todas_las_reservas_de_todos_los_clientes(): void
    {
        $week = $this->nextMonday();

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => Hash::make('password'),
            'role' => 'administrador',
            'weekly_hours' => 0,
        ]);

        $clienteA = User::create([
            'name' => 'Cliente A',
            'email' => 'a@test.test',
            'password' => Hash::make('password'),
            'role' => 'cliente',
            'weekly_hours' => 5,
        ]);

        $clienteB = User::create([
            'name' => 'Cliente B',
            'email' => 'b@test.test',
            'password' => Hash::make('password'),
            'role' => 'cliente',
            'weekly_hours' => 5,
        ]);

        $slot1 = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $slot2 = Slot::create(['day_of_week' => 1, 'start_time' => '09:00:00', 'capacity' => 4, 'status' => 'abierta']);

        $r1 = Reservation::create(['user_id' => $clienteA->id, 'slot_id' => $slot1->id, 'week_start' => $week, 'status' => 'confirmada']);
        $r2 = Reservation::create(['user_id' => $clienteB->id, 'slot_id' => $slot2->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/reservations');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($r1->id, $ids, 'Admin debe ver reserva de cliente A sin filtro');
        $this->assertContains($r2->id, $ids, 'Admin debe ver reserva de cliente B sin filtro');
        $this->assertCount(2, $ids);

        // Con filtro user_id, solo ve las de ese cliente
        $filtered = $this->getJson('/api/reservations?user_id=' . $clienteA->id);
        $filtered->assertStatus(200);
        $filteredIds = collect($filtered->json('data'))->pluck('id')->toArray();
        $this->assertContains($r1->id, $filteredIds);
        $this->assertNotContains($r2->id, $filteredIds);
        $this->assertCount(1, $filteredIds);
    }

    public function test_cliente_sin_filtro_solo_ve_suyas(): void
    {
        $week = $this->nextMonday();

        $clienteA = User::create(['name' => 'A', 'email' => 'a2@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $clienteB = User::create(['name' => 'B', 'email' => 'b2@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);

        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '10:00:00', 'capacity' => 4, 'status' => 'abierta']);

        $rA = Reservation::create(['user_id' => $clienteA->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);
        Reservation::create(['user_id' => $clienteB->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($clienteA);

        $response = $this->getJson('/api/reservations');
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($rA->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_listado_de_reservas_admin_incluye_datos_del_cliente(): void
    {
        $week = $this->nextMonday();
        $admin = User::create(['name' => 'Admin2', 'email' => 'admin2@test.test', 'password' => Hash::make('password'), 'role' => 'administrador', 'weekly_hours' => 0]);
        $cliente = User::create(['name' => 'Cliente UserDatos', 'email' => 'userdatos@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $slot = Slot::create(['day_of_week' => 2, 'start_time' => '11:00:00', 'capacity' => 4, 'status' => 'abierta']);
        Reservation::create(['user_id' => $cliente->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/reservations?week_start=$week");
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $first = $data[0];
        $this->assertArrayHasKey('user', $first, 'Cada reserva debe incluir objeto user anidado');
        $this->assertArrayHasKey('name', $first['user']);
        $this->assertArrayHasKey('email', $first['user']);
        $this->assertEquals('Cliente UserDatos', $first['user']['name']);
        $this->assertEquals('userdatos@test.test', $first['user']['email']);
    }
}
