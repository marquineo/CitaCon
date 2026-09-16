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

class SlotCapacityValidationTest extends TestCase
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

    public function test_bajar_capacity_por_debajo_de_ocupacion_vigente_rechaza_con_422(): void
    {
        $admin = $this->admin();
        $weekCurrent = $this->nextMonday();
        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);

        // Ocupación vigente 3/4 en semana actual
        for ($i = 0; $i < 3; $i++) {
            $u = User::create(['name' => "c$i", 'email' => "c$i@test.test", 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
            Reservation::create(['user_id' => $u->id, 'slot_id' => $slot->id, 'week_start' => $weekCurrent, 'status' => 'confirmada']);
        }

        Sanctum::actingAs($admin);

        // Intentar bajar a 2 (menor que 3) debe fallar 422
        $resp = $this->putJson("/api/slots/{$slot->id}", [
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'capacity' => 2,
        ]);

        $resp->assertStatus(422);
        $payload = json_encode($resp->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('no puede ser inferior', strtolower($payload));
        $this->assertStringContainsString('reservas existentes', strtolower($payload));

        // Verificar que no se modificó
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'capacity' => 4]);
    }

    public function test_historico_semanas_pasadas_se_ignora_al_bajar_capacity(): void
    {
        $admin = $this->admin();
        $weekPast = $this->pastMonday();
        $weekCurrent = $this->nextMonday();

        $slot = Slot::create(['day_of_week' => 2, 'start_time' => '09:00:00', 'capacity' => 4, 'status' => 'abierta']);

        // 5 reservas en semana pasada (histórico) — debe ignorarse
        for ($i = 0; $i < 5; $i++) {
            $u = User::create(['name' => "past$i", 'email' => "past$i@test.test", 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
            Reservation::create(['user_id' => $u->id, 'slot_id' => $slot->id, 'week_start' => $weekPast, 'status' => 'confirmada']);
        }

        // 1 reserva en semana actual
        $uCurrent = User::create(['name' => 'current', 'email' => 'current@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        Reservation::create(['user_id' => $uCurrent->id, 'slot_id' => $slot->id, 'week_start' => $weekCurrent, 'status' => 'confirmada']);

        Sanctum::actingAs($admin);

        // Bajar a 2 debe permitirse porque histórico pasado se ignora (vigente es 1)
        $resp = $this->putJson("/api/slots/{$slot->id}", [
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'capacity' => 2,
        ]);

        $resp->assertStatus(200);
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'capacity' => 2]);

        // Bajar a 1 también debe permitirse (vigente 1)
        $resp2 = $this->putJson("/api/slots/{$slot->id}", [
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'capacity' => 1,
        ]);
        $resp2->assertStatus(200);

        // Bajar a 0 debe fallar por validación min:1, no por ocupación (pero probamos que 0 no es permitido)
        $resp3 = $this->putJson("/api/slots/{$slot->id}", [
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'capacity' => 0,
        ]);
        $resp3->assertStatus(422);
    }

    public function test_subir_capacity_siempre_se_permite(): void
    {
        $admin = $this->admin();
        $weekCurrent = $this->nextMonday();
        $slot = Slot::create(['day_of_week' => 3, 'start_time' => '10:00:00', 'capacity' => 2, 'status' => 'abierta']);

        $u1 = User::create(['name' => 'u1', 'email' => 'u1@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        $u2 = User::create(['name' => 'u2', 'email' => 'u2@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        Reservation::create(['user_id' => $u1->id, 'slot_id' => $slot->id, 'week_start' => $weekCurrent, 'status' => 'confirmada']);
        Reservation::create(['user_id' => $u2->id, 'slot_id' => $slot->id, 'week_start' => $weekCurrent, 'status' => 'confirmada']);

        Sanctum::actingAs($admin);

        // Subir de 2 a 6 debe permitirse aunque haya 2 reservas vigentes
        $resp = $this->putJson("/api/slots/{$slot->id}", [
            'day_of_week' => 3,
            'start_time' => '10:00:00',
            'capacity' => 6,
        ]);

        $resp->assertStatus(200);
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'capacity' => 6]);

        // Subir aún más a 10
        $resp2 = $this->putJson("/api/slots/{$slot->id}", [
            'day_of_week' => 3,
            'start_time' => '10:00:00',
            'capacity' => 10,
        ]);
        $resp2->assertStatus(200);
    }

    public function test_concurrencia_reserva_vs_reduccion_capacity_no_deja_sobre_aforo(): void
    {
        // Franja con capacidad 2 y 1 reserva (1/2): queda 1 plaza libre
        $week = $this->nextMonday();
        $slot = Slot::create(['day_of_week' => 4, 'start_time' => '11:00:00', 'capacity' => 2, 'status' => 'abierta']);

        $existingUser = User::create(['name' => 'existing', 'email' => 'existing@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 5]);
        Reservation::create(['user_id' => $existingUser->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);

        $admin = $this->admin();
        $clients = [];
        for ($i = 0; $i < 3; $i++) {
            $clients[] = User::create([
                'name' => "concurrent{$i}",
                'email' => "concurrent{$i}@test.test",
                'password' => Hash::make('password'),
                'role' => 'cliente',
                'weekly_hours' => 5,
            ]);
        }

        // Simular peticiones simultáneas: clientes intentan reservar última plaza mientras admin intenta reducir a 1
        // Con lockForUpdate, el resultado final nunca debe tener más reservas que capacidad
        $reservationSuccess = 0;
        $reductionSuccess = 0;

        // Escenario A: reservas primero, luego intento de reducción
        foreach ($clients as $client) {
            Sanctum::actingAs($client);
            $r = $this->postJson('/api/reservations', ['slot_id' => $slot->id, 'week_start' => $week]);
            if ($r->status() === 201) $reservationSuccess++;

            Sanctum::actingAs($admin);
            $u = $this->putJson("/api/slots/{$slot->id}", [
                'day_of_week' => 4,
                'start_time' => '11:00:00',
                'capacity' => 1,
            ]);
            if ($u->status() === 200) $reductionSuccess++;
        }

        $slot->refresh();
        $finalCount = Reservation::where('slot_id', $slot->id)->where('week_start', $week)->count();
        $finalCapacity = $slot->capacity;

        // Invariante final: nunca más reservas que capacidad
        $this->assertLessThanOrEqual($finalCapacity, $finalCount + 0, "Nunca debe haber más reservas ($finalCount) que capacidad final ($finalCapacity)");
        $this->assertLessThanOrEqual($finalCapacity, $finalCount, "Sobre-aforo detectado: $finalCount reservas con capacidad $finalCapacity");

        // Escenario B: nuevo slot limpio, intentar reducir y reservar en orden inverso
        $slot2 = Slot::create(['day_of_week' => 5, 'start_time' => '12:00:00', 'capacity' => 2, 'status' => 'abierta']);
        Reservation::create(['user_id' => $existingUser->id, 'slot_id' => $slot2->id, 'week_start' => $week, 'status' => 'confirmada']);

        // Admin reduce primero a 1 (debe tener éxito, vigente 1/2)
        Sanctum::actingAs($admin);
        $red = $this->putJson("/api/slots/{$slot2->id}", [
            'day_of_week' => 5,
            'start_time' => '12:00:00',
            'capacity' => 1,
        ]);
        $red->assertStatus(200);

        // Ahora cliente intenta reservar última plaza que ya no existe (capacidad 1, ocupación 1)
        Sanctum::actingAs($clients[0]);
        $rBlocked = $this->postJson('/api/reservations', ['slot_id' => $slot2->id, 'week_start' => $week]);
        $rBlocked->assertStatus(409);

        $slot2->refresh();
        $finalCount2 = Reservation::where('slot_id', $slot2->id)->where('week_start', $week)->count();
        $this->assertEquals(1, $finalCount2);
        $this->assertEquals(1, $slot2->capacity);

        // Verificación global: ningún slot debe tener sobre-aforo vigente
        $currentWeekStart = Carbon::now('Europe/Madrid')->startOfWeek(Carbon::MONDAY)->toDateString();
        $overCapacity = DB::table('reservations')
            ->select('slot_id', 'week_start', DB::raw('COUNT(*) as cnt'))
            ->where('week_start', '>=', $currentWeekStart)
            ->groupBy('slot_id', 'week_start')
            ->get()
            ->filter(function ($row) {
                $slot = Slot::find($row->slot_id);
                return $slot && $row->cnt > $slot->capacity;
            });
        $this->assertCount(0, $overCapacity, 'Ninguna franja debe tener más reservas que su capacidad final');
    }
}
