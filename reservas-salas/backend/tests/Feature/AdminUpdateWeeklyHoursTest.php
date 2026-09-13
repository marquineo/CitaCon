<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class AdminUpdateWeeklyHoursTest extends TestCase
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
            'weekly_hours' => 2,
        ]);
    }

    public function test_admin_puede_actualizar_weekly_hours_valido_y_se_refleja_en_db(): void
    {
        $admin = $this->admin();
        $target = $this->cliente();

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/users/{$target->id}/weekly-hours", [
            'weekly_hours' => 5,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.weekly_hours', 5);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'weekly_hours' => 5]);

        // Verificar que el cambio aplica a validaciones futuras (no necesita test adicional, pero comprobamos DB)
        $target->refresh();
        $this->assertEquals(5, $target->weekly_hours);
    }

    public function test_cliente_no_puede_actualizar_weekly_hours_recibe_403(): void
    {
        $cliente = $this->cliente();
        $target = User::create([
            'name' => 'Otro',
            'email' => 'otro@test.test',
            'password' => Hash::make('password'),
            'role' => 'cliente',
            'weekly_hours' => 1,
        ]);

        Sanctum::actingAs($cliente);

        $response = $this->patchJson("/api/users/{$target->id}/weekly-hours", [
            'weekly_hours' => 10,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'weekly_hours' => 1]);
        $this->assertDatabaseMissing('users', ['id' => $target->id, 'weekly_hours' => 10]);
    }

    public function test_valor_invalido_negativo_o_mayor_que_max_rechazado_con_422(): void
    {
        $admin = $this->admin();
        $target = $this->cliente();

        Sanctum::actingAs($admin);

        // Negativo
        $r1 = $this->patchJson("/api/users/{$target->id}/weekly-hours", ['weekly_hours' => -1]);
        $r1->assertStatus(422);
        $r1->assertJsonValidationErrors(['weekly_hours']);

        // Mayor que 50 (max)
        $r2 = $this->patchJson("/api/users/{$target->id}/weekly-hours", ['weekly_hours' => 99]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['weekly_hours']);

        // También probar >50 límite exacto 51
        $r3 = $this->patchJson("/api/users/{$target->id}/weekly-hours", ['weekly_hours' => 51]);
        $r3->assertStatus(422);

        // Verificar que no se modificó
        $this->assertDatabaseHas('users', ['id' => $target->id, 'weekly_hours' => 2]);
    }
}
