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

class SlotBlockingCascadeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => Hash::make('password'),
            'role' => 'administrador',
            'weekly_hours' => 0,
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

    public function test_bloquear_franja_con_reservas_elimina_solo_futuras_y_hard_delete(): void
    {
        $admin = $this->admin();
        $slot = Slot::create(['day_of_week' => 3, 'start_time' => '18:00:00', 'capacity' => 4, 'status' => 'abierta']);

        $weekCurrent = $this->nextMonday();
        $weekPast = $this->pastMonday();

        $c1 = User::create(['name' => 'c1', 'email' => 'c1@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $c2 = User::create(['name' => 'c2', 'email' => 'c2@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);

        // 2 reservas en semana futura (deben ser eliminadas)
        Reservation::create(['user_id' => $c1->id, 'slot_id' => $slot->id, 'week_start' => $weekCurrent, 'status' => 'confirmada']);
        Reservation::create(['user_id' => $c2->id, 'slot_id' => $slot->id, 'week_start' => $weekCurrent, 'status' => 'confirmada']);

        // 1 reserva en semana pasada (no debe tocarse)
        $pastReservation = Reservation::create(['user_id' => $c1->id, 'slot_id' => $slot->id, 'week_start' => $weekPast, 'status' => 'confirmada']);

        Sanctum::actingAs($admin);

        $resp = $this->patchJson("/api/slots/{$slot->id}/block");
        $resp->assertStatus(200);
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'status' => 'bloqueada']);

        // Futuras eliminadas (hard DELETE)
        $this->assertEquals(0, Reservation::where('slot_id', $slot->id)->where('week_start', $weekCurrent)->count());
        $this->assertDatabaseMissing('reservations', ['slot_id' => $slot->id, 'week_start' => $weekCurrent]);

        // Pasada intacta
        $this->assertDatabaseHas('reservations', ['id' => $pastReservation->id]);
        $this->assertEquals(1, Reservation::where('slot_id', $slot->id)->where('week_start', $weekPast)->count());

        // Cupo liberado: cliente puede volver a reservar la misma franja/semana futura
        Sanctum::actingAs($c1);
        $r = $this->postJson('/api/reservations', ['slot_id' => $slot->id, 'week_start' => $weekCurrent]);
        // Debe fallar por bloqueada (409), no por UNIQUE
        $r->assertStatus(409);

        // Desbloquear y re-reservar debe funcionar
        Sanctum::actingAs($admin);
        $this->patchJson("/api/slots/{$slot->id}/unblock")->assertStatus(200);

        Sanctum::actingAs($c1);
        $r2 = $this->postJson('/api/reservations', ['slot_id' => $slot->id, 'week_start' => $weekCurrent]);
        $r2->assertStatus(201);
    }

    public function test_concurrencia_reserva_vs_bloqueo_no_deja_fantasma(): void
    {
        // Simula peticiones simultáneas: clientes intentan reservar mientras admin bloquea.
        // Patrón tomado de ConcurrencyTest: 20 intentos a última plaza, aquí 5 reservas + 1 bloqueo.
        // El resultado final debe ser consistente: nunca una reserva confirmada en franja bloqueada.
        $week = $this->nextMonday();
        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 1, 'status' => 'abierta']);

        $admin = $this->admin();
        $clients = [];
        for ($i = 0; $i < 5; $i++) {
            $clients[] = \App\Models\User::create([
                'name' => "c$i",
                'email' => "c{$i}_concurrent@test.test",
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'cliente',
                'weekly_hours' => 5,
            ]);
        }

        // Escenario A: reservas primero, luego bloqueo (el bloqueo debe cancelar la reserva si se creó)
        $firstClient = $clients[0];
        \Laravel\Sanctum\Sanctum::actingAs($firstClient);
        $r1 = $this->postJson('/api/reservations', ['slot_id' => $slot->id, 'week_start' => $week]);
        $this->assertEquals(201, $r1->status());

        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $block = $this->patchJson("/api/slots/{$slot->id}/block");
        $block->assertStatus(200);

        $slot->refresh();
        $countAfterBlock = \App\Models\Reservation::where('slot_id', $slot->id)->where('week_start', $week)->count();
        $this->assertEquals('bloqueada', $slot->status);
        $this->assertEquals(0, $countAfterBlock, 'Tras bloquear, no debe quedar reserva confirmada (cascada hard DELETE)');

        // Escenario B: intentar reservar en franja ya bloqueada debe fallar 409
        \Laravel\Sanctum\Sanctum::actingAs($clients[1]);
        $rBlocked = $this->postJson('/api/reservations', ['slot_id' => $slot->id, 'week_start' => $week]);
        $rBlocked->assertStatus(409);
        $this->assertEquals(0, \App\Models\Reservation::where('slot_id', $slot->id)->where('week_start', $week)->count());

        // Escenario C: desbloquear y verificar que se puede volver a reservar (sin fantasma)
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->patchJson("/api/slots/{$slot->id}/unblock")->assertStatus(200);

        \Laravel\Sanctum\Sanctum::actingAs($clients[2]);
        $rAfterUnblock = $this->postJson('/api/reservations', ['slot_id' => $slot->id, 'week_start' => $week]);
        $rAfterUnblock->assertStatus(201);
        $slot->refresh();
        $this->assertEquals('abierta', $slot->status);
        $this->assertEquals(1, \App\Models\Reservation::where('slot_id', $slot->id)->where('week_start', $week)->count());

        // Escenario D: concurrencia realista — 4 clientes intentan última plaza mientras admin bloquea
        // Limpiamos y preparamos nuevo slot para este sub-escenario
        $slot2 = Slot::create(['day_of_week' => 2, 'start_time' => '09:00:00', 'capacity' => 1, 'status' => 'abierta']);
        $week2 = $week;

        $success = 0;
        $blockedSeen = false;
        foreach ($clients as $c) {
            \Laravel\Sanctum\Sanctum::actingAs($c);
            $resp = $this->postJson('/api/reservations', ['slot_id' => $slot2->id, 'week_start' => $week2]);
            if ($resp->status() === 201) $success++;

            \Laravel\Sanctum\Sanctum::actingAs($admin);
            $b = $this->patchJson("/api/slots/{$slot2->id}/block");
            if ($b->status() === 200) $blockedSeen = true;
        }

        $slot2->refresh();
        $finalCount = \App\Models\Reservation::where('slot_id', $slot2->id)->where('week_start', $week2)->count();
        if ($slot2->status === 'bloqueada') {
            $this->assertEquals(0, $finalCount, 'Franja bloqueada nunca debe tener reservas confirmadas (fantasma)');
        } else {
            $this->assertLessThanOrEqual(1, $finalCount, 'Si no quedó bloqueada, como máximo 1 reserva por UNIQUE + aforo 1');
        }
        // Verificación final: nunca fantasma
        $ghostCount = \App\Models\Reservation::whereHas('slot', fn($q) => $q->where('status', 'bloqueada'))
            ->where('week_start', '>=', \Carbon\Carbon::now('Europe/Madrid')->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString())
            ->count();
        $this->assertEquals(0, $ghostCount, 'Nunca debe existir reserva confirmada en franja bloqueada');
    }
}
