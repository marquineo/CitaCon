<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Slot;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\QueryException;
use Carbon\Carbon;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_invalido_es_rechazado_por_enum_de_bd(): void
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'test@test.test',
            'password' => Hash::make('password'),
            'role' => 'cliente',
            'weekly_hours' => 5,
        ]);

        $slot = Slot::create([
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'capacity' => 4,
            'status' => 'abierta',
        ]);

        $week = Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->expectException(QueryException::class);

        Reservation::create([
            'user_id' => $user->id,
            'slot_id' => $slot->id,
            'week_start' => $week,
            'status' => 'cancelada', // ya eliminado del ENUM, solo queda 'confirmada'
        ]);
    }

    public function test_unique_constraint_rechaza_duplicado_a_nivel_modelo(): void
    {
        $user = User::create([
            'name' => 'Dup',
            'email' => 'dup@test.test',
            'password' => Hash::make('password'),
            'role' => 'cliente',
            'weekly_hours' => 5,
        ]);

        $slot = Slot::create([
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'capacity' => 4,
            'status' => 'abierta',
        ]);

        $week = Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();

        // Primera reserva directa vía modelo
        Reservation::create([
            'user_id' => $user->id,
            'slot_id' => $slot->id,
            'week_start' => $week,
            'status' => 'confirmada',
        ]);

        $this->assertEquals(1, Reservation::where('user_id', $user->id)->where('slot_id', $slot->id)->where('week_start', $week)->count());

        // Segunda idéntica sin pasar por ReservationService debe fallar por UNIQUE
        $this->expectException(QueryException::class);

        Reservation::create([
            'user_id' => $user->id,
            'slot_id' => $slot->id,
            'week_start' => $week,
            'status' => 'confirmada',
        ]);
    }
}
