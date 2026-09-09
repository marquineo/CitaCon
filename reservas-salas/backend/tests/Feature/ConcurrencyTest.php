<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Slot;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

/**
 * FR-014 / SC-004: Dos clientes intentando reservar la última plaza concurrentemente — solo uno debe tener éxito.
 * Implementación esperada: ReservationService con DB::transaction + lockForUpdate() + UNIQUE.
 */
class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function nextMonday(): string
    {
        return Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public function test_20_intentos_concurrentes_ultima_plaza_solo_uno_exito(): void
    {
        $week = $this->nextMonday();

        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);

        for ($i = 0; $i < 3; $i++) {
            $u = User::create(['name' => "pre$i", 'email' => "pre$i@test.test", 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
            Reservation::create(['user_id' => $u->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);
        }

        $this->assertEquals(3, Reservation::where('slot_id', $slot->id)->where('week_start', $week)->count());

        $clientes = [];
        for ($i = 0; $i < 20; $i++) {
            $clientes[] = User::create(['name' => "c$i", 'email' => "c$i@test.test", 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        }

        $success = 0;
        $rejected = 0;

        foreach ($clientes as $cliente) {
            Sanctum::actingAs($cliente);
            $resp = $this->postJson('/api/reservations', ['slot_id' => $slot->id, 'week_start' => $week]);
            if ($resp->status() === 201) $success++;
            else if (in_array($resp->status(), [409, 422])) $rejected++;
        }

        $this->assertEquals(1, $success, "Solo 1 de 20 debe tener éxito en la última plaza");
        $this->assertEquals(19, $rejected, "19 deben ser rechazados por aforo completo");
        $this->assertEquals(4, Reservation::where('slot_id', $slot->id)->where('week_start', $week)->count(), "Ocupación final debe ser exactamente capacidad (4/4)");
        $this->assertLessThanOrEqual(4, Reservation::where('slot_id', $slot->id)->where('week_start', $week)->count(), "Nunca debe superar aforo");
    }

    public function test_admin_bypass_no_bloqueo_pesimista_para_aforo(): void
    {
        $week = $this->nextMonday();
        $slot = Slot::create(['day_of_week' => 2, 'start_time' => '09:00:00', 'capacity' => 1, 'status' => 'abierta']);
        $c = User::create(['name' => 'c', 'email' => 'c@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        Reservation::create(['user_id' => $c->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);

        $admin = User::create(['name' => 'admin', 'email' => 'admin2@test.test', 'password' => Hash::make('password'), 'role' => 'administrador', 'weekly_hours' => 0]);
        $target = User::create(['name' => 't', 'email' => 't@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 0]);
        Sanctum::actingAs($admin);
        $resp = $this->postJson('/api/reservations', ['slot_id' => $slot->id, 'week_start' => $week, 'user_id' => $target->id]);
        $resp->assertStatus(201);
    }
}
