<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Slot;
use App\Models\Reservation;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $weekStart = Carbon::now('Europe/Madrid')->startOfWeek(Carbon::MONDAY)->toDateString();

        $admin = User::updateOrCreate(
            ['email' => 'admin@gym.test'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('password'),
                'role' => 'administrador',
                'weekly_hours' => 0,
            ]
        );

        $cliente1 = User::updateOrCreate(
            ['email' => 'cliente-a@gym.test'],
            [
                'name' => 'Cliente A',
                'password' => Hash::make('password'),
                'role' => 'cliente',
                'weekly_hours' => 3,
            ]
        );

        $cliente2 = User::updateOrCreate(
            ['email' => 'cliente-b@gym.test'],
            [
                'name' => 'Cliente B',
                'password' => Hash::make('password'),
                'role' => 'cliente',
                'weekly_hours' => 5,
            ]
        );

        $clienteCero = User::updateOrCreate(
            ['email' => 'cliente-cero@gym.test'],
            [
                'name' => 'Cliente Cero',
                'password' => Hash::make('password'),
                'role' => 'cliente',
                'weekly_hours' => 0,
            ]
        );

        $slotAbierta1 = Slot::updateOrCreate(
            ['day_of_week' => 1, 'start_time' => '08:00:00'],
            ['capacity' => 1, 'status' => 'abierta']
        );

        $slotBloqueada = Slot::updateOrCreate(
            ['day_of_week' => 2, 'start_time' => '09:00:00'],
            ['capacity' => 4, 'status' => 'bloqueada']
        );

        $slotAbierta2 = Slot::updateOrCreate(
            ['day_of_week' => 3, 'start_time' => '10:00:00'],
            ['capacity' => 2, 'status' => 'abierta']
        );

        $slotLlena = Slot::updateOrCreate(
            ['day_of_week' => 4, 'start_time' => '11:00:00'],
            ['capacity' => 1, 'status' => 'abierta']
        );

        Reservation::where('slot_id', $slotLlena->id)->where('week_start', $weekStart)->delete();
        Reservation::create([
            'user_id' => $cliente1->id,
            'slot_id' => $slotLlena->id,
            'week_start' => $weekStart,
            'status' => 'confirmada',
        ]);

        Reservation::whereIn('slot_id', [$slotAbierta1->id, $slotBloqueada->id, $slotAbierta2->id])
            ->where('week_start', $weekStart)
            ->delete();

        $this->command?->info("DemoSeeder: admin, 3 clientes (3h,5h,0h), 4 slots (08:00 1 abierta 0/1, 09:00 bloqueada, 10:00 2 abierta 0/2, 11:00 1 llena 1/1 en $weekStart)");
    }
}
