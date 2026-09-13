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

class IsolationCancelTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email, string $role, int $hours): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'weekly_hours' => $hours,
        ]);
    }

    public function test_cliente_no_puede_inferir_existencia_reserva_ajena_via_cancel_o_view(): void
    {
        $owner = $this->makeUser('owner@test.test', 'cliente', 3);
        $attacker = $this->makeUser('attacker@test.test', 'cliente', 3);
        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $week = Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();

        $reservation = Reservation::create([
            'user_id' => $owner->id,
            'slot_id' => $slot->id,
            'week_start' => $week,
            'status' => 'confirmada',
        ]);

        Sanctum::actingAs($attacker);

        // Intentar DELETE ajeno -> 404 indistinguible
        $del = $this->deleteJson("/api/reservations/{$reservation->id}");
        $del->assertStatus(404);
        $this->assertStringNotContainsString((string) $owner->id, json_encode($del->json()));

        // Intentar GET ajeno -> 404 indistinguible (si existe endpoint show)
        $get = $this->getJson("/api/reservations/{$reservation->id}");
        $get->assertStatus(404);

        // Listado solo ve propias (0 para attacker)
        $list = $this->getJson('/api/reservations');
        $list->assertStatus(200);
        $ids = collect($list->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($reservation->id, $ids);
        $this->assertCount(0, $ids);
    }

    public function test_listado_no_filtra_ids_ajenos(): void
    {
        $owner = $this->makeUser('owner2@test.test', 'cliente', 3);
        $attacker = $this->makeUser('attacker2@test.test', 'cliente', 3);
        $slot = Slot::create(['day_of_week' => 2, 'start_time' => '09:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $week = Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();

        Reservation::create(['user_id' => $owner->id, 'slot_id' => $slot->id, 'week_start' => $week, 'status' => 'confirmada']);

        Sanctum::actingAs($attacker);
        $list = $this->getJson('/api/reservations?user_id=' . $owner->id);
        $list->assertStatus(200);
        // Cliente no puede usar ?user_id para ver ajenas; debe seguir viendo 0
        $this->assertCount(0, $list->json('data'));
    }
}
