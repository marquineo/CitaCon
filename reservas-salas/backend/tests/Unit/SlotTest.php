<?php

namespace Tests\Unit;

use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class SlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_invalido_es_rechazado_por_enum_de_bd(): void
    {
        $this->expectException(QueryException::class);

        Slot::create([
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'capacity' => 4,
            'status' => 'cerrada', // fuera del ENUM abierta/bloqueada
        ]);
    }

    public function test_day_of_week_fuera_de_rango_es_rechazado(): void
    {
        $this->expectException(QueryException::class);

        Slot::create([
            'day_of_week' => 6, // fuera de 1-5, debe fallar CHECK
            'start_time' => '08:00:00',
            'capacity' => 4,
            'status' => 'abierta',
        ]);
    }

    public function test_scope_abierta_y_bloqueada_filtran_correctamente(): void
    {
        $abierta = Slot::create([
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'capacity' => 4,
            'status' => 'abierta',
        ]);

        $bloqueada = Slot::create([
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'capacity' => 4,
            'status' => 'bloqueada',
        ]);

        $abiertas = Slot::abierta()->get();
        $bloqueadas = Slot::bloqueada()->get();

        $this->assertCount(1, $abiertas);
        $this->assertTrue($abiertas->contains($abierta));
        $this->assertFalse($abiertas->contains($bloqueada));

        $this->assertCount(1, $bloqueadas);
        $this->assertTrue($bloqueadas->contains($bloqueada));
        $this->assertFalse($bloqueadas->contains($abierta));
    }
}
