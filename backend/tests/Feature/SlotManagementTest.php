<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class SlotManagementTest extends TestCase
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

    private function cliente(): User
    {
        return User::create([
            'name' => 'Cliente',
            'email' => 'cliente@test.test',
            'password' => Hash::make('password'),
            'role' => 'cliente',
            'weekly_hours' => 5,
        ]);
    }

    public function test_admin_puede_crear_franja_201(): void
    {
        Sanctum::actingAs($this->admin());

        $resp = $this->postJson('/api/slots', [
            'day_of_week' => 3,
            'start_time' => '18:00:00',
            'capacity' => 4,
        ]);

        $resp->assertStatus(201);
        $resp->assertJsonPath('data.day_of_week', 3);
        $this->assertDatabaseHas('slots', ['day_of_week' => 3, 'start_time' => '18:00:00']);
    }

    public function test_crear_franja_fuera_horario_422(): void
    {
        Sanctum::actingAs($this->admin());

        $resp = $this->postJson('/api/slots', [
            'day_of_week' => 6, // sábado
            'start_time' => '10:00:00',
            'capacity' => 4,
        ]);
        $resp->assertStatus(422);

        $resp2 = $this->postJson('/api/slots', [
            'day_of_week' => 1,
            'start_time' => '06:00:00', // antes de 07:00
            'capacity' => 4,
        ]);
        $resp2->assertStatus(422);
    }

    public function test_crear_franja_duplicada_422(): void
    {
        Sanctum::actingAs($this->admin());
        Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);

        $resp = $this->postJson('/api/slots', [
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'capacity' => 4,
        ]);

        $resp->assertStatus(422);
        $this->assertStringContainsString('Ya existe', json_encode($resp->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function test_admin_puede_editar_y_eliminar_franja(): void
    {
        Sanctum::actingAs($this->admin());
        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);

        $resp = $this->putJson("/api/slots/{$slot->id}", [
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'capacity' => 6,
        ]);
        $resp->assertStatus(200);
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'start_time' => '09:00:00', 'capacity' => 6]);

        $del = $this->deleteJson("/api/slots/{$slot->id}");
        $del->assertStatus(200);
        $this->assertDatabaseMissing('slots', ['id' => $slot->id]);
    }

    public function test_admin_puede_bloquear_y_desbloquear_franja(): void
    {
        Sanctum::actingAs($this->admin());
        $slot = Slot::create(['day_of_week' => 2, 'start_time' => '10:00:00', 'capacity' => 4, 'status' => 'abierta']);

        $block = $this->patchJson("/api/slots/{$slot->id}/block");
        $block->assertStatus(200);
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'status' => 'bloqueada']);

        $unblock = $this->patchJson("/api/slots/{$slot->id}/unblock");
        $unblock->assertStatus(200);
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'status' => 'abierta']);
    }

    public function test_cliente_no_puede_gestionar_franjas_403(): void
    {
        Sanctum::actingAs($this->cliente());

        $resp = $this->postJson('/api/slots', ['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4]);
        $resp->assertStatus(403);

        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $resp2 = $this->putJson("/api/slots/{$slot->id}", ['capacity' => 6]);
        $resp2->assertStatus(403);

        $resp3 = $this->patchJson("/api/slots/{$slot->id}/block");
        $resp3->assertStatus(403);
    }

    public function test_admin_puede_crear_franja_con_entrenador(): void
    {
        Sanctum::actingAs($this->admin());

        $resp = $this->postJson('/api/slots', [
            'day_of_week' => 2,
            'start_time' => '11:00:00',
            'capacity' => 4,
            'trainer' => 'Carlos',
        ]);

        $resp->assertStatus(201);
        $resp->assertJsonPath('data.trainer', 'Carlos');
        $this->assertDatabaseHas('slots', ['day_of_week' => 2, 'start_time' => '11:00:00', 'trainer' => 'Carlos']);

        // Verifica que el listado incluye trainer
        $list = $this->getJson('/api/slots');
        $list->assertStatus(200);
        $found = collect($list->json('data'))->firstWhere('id', $resp->json('data.id'));
        $this->assertNotNull($found);
        $this->assertEquals('Carlos', $found['trainer']);
    }

    public function test_crear_franja_sin_entrenador_usa_null_o_valor_por_defecto(): void
    {
        Sanctum::actingAs($this->admin());

        $resp = $this->postJson('/api/slots', [
            'day_of_week' => 3,
            'start_time' => '12:00:00',
            'capacity' => 4,
        ]);

        $resp->assertStatus(201);
        $this->assertTrue(array_key_exists('trainer', $resp->json('data')), 'Respuesta debe incluir trainer aunque sea null');
        $this->assertNull($resp->json('data.trainer'));

        $this->assertDatabaseHas('slots', ['day_of_week' => 3, 'start_time' => '12:00:00', 'trainer' => null]);

        // Listado también expone trainer
        $list = $this->getJson('/api/slots');
        $found = collect($list->json('data'))->firstWhere('id', $resp->json('data.id'));
        $this->assertTrue(array_key_exists('trainer', $found));
        $this->assertNull($found['trainer']);

        // Validación: trainer inválido debe dar 422
        $respInvalid = $this->postJson('/api/slots', [
            'day_of_week' => 3,
            'start_time' => '13:00:00',
            'capacity' => 4,
            'trainer' => 'Bob',
        ]);
        $respInvalid->assertStatus(422);
        $respInvalid->assertJsonValidationErrors(['trainer']);
    }
}
