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

class AdminUsersCrudTest extends TestCase
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

    public function test_admin_puede_crear_cliente_con_datos_validos_201(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/users', [
            'name' => 'Nuevo Cliente',
            'email' => 'nuevo@test.test',
            'weekly_hours' => 3,
            'password' => 'password123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.email', 'nuevo@test.test');
        $this->assertDatabaseHas('users', ['email' => 'nuevo@test.test', 'role' => 'cliente', 'weekly_hours' => 3]);

        // Con 0 horas también debe permitirse
        $r2 = $this->postJson('/api/users', [
            'name' => 'Cero Horas',
            'email' => 'cero@test.test',
            'weekly_hours' => 0,
            'password' => 'password123',
        ]);
        $r2->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'cero@test.test', 'weekly_hours' => 0]);
    }

    public function test_admin_crear_cliente_rechaza_datos_invalidos_422(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        // Email duplicado
        User::create(['name' => 'Exist', 'email' => 'dup@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 1]);
        $dup = $this->postJson('/api/users', [
            'name' => 'Dup',
            'email' => 'dup@test.test',
            'weekly_hours' => 2,
            'password' => 'password123',
        ]);
        $dup->assertStatus(422);
        $dup->assertJsonValidationErrors(['email']);

        // Formato email inválido
        $invalid = $this->postJson('/api/users', [
            'name' => 'Bad',
            'email' => 'no-es-email',
            'weekly_hours' => 2,
            'password' => 'password123',
        ]);
        $invalid->assertStatus(422);
        $invalid->assertJsonValidationErrors(['email']);

        // weekly_hours negativo
        $neg = $this->postJson('/api/users', [
            'name' => 'Neg',
            'email' => 'neg@test.test',
            'weekly_hours' => -1,
            'password' => 'password123',
        ]);
        $neg->assertStatus(422);
        $neg->assertJsonValidationErrors(['weekly_hours']);

        // Ausencia de password debe rechazarse con 422 (password obligatorio, min:8)
        $noPass = $this->postJson('/api/users', [
            'name' => 'SinPass',
            'email' => 'sinpass@test.test',
            'weekly_hours' => 2,
        ]);
        $noPass->assertStatus(422);
        $noPass->assertJsonValidationErrors(['password']);

        // Password demasiado corto (<8)
        $shortPass = $this->postJson('/api/users', [
            'name' => 'Short',
            'email' => 'short@test.test',
            'weekly_hours' => 2,
            'password' => 'short',
        ]);
        $shortPass->assertStatus(422);
        $shortPass->assertJsonValidationErrors(['password']);
    }

    public function test_cliente_no_puede_crear_usuarios_403(): void
    {
        $cliente = $this->cliente();
        Sanctum::actingAs($cliente);

        $resp = $this->postJson('/api/users', [
            'name' => 'Intento',
            'email' => 'intento@test.test',
            'weekly_hours' => 2,
            'password' => 'password123',
        ]);

        $resp->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'intento@test.test']);
    }

    public function test_admin_puede_eliminar_cliente_y_cascada_hard_delete_reservas(): void
    {
        $admin = $this->admin();
        $cliente = $this->cliente();
        $slot = Slot::create(['day_of_week' => 1, 'start_time' => '08:00:00', 'capacity' => 4, 'status' => 'abierta']);
        $weekPast = Carbon::now('Europe/Madrid')->subWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekFuture = Carbon::now('Europe/Madrid')->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();

        Reservation::create(['user_id' => $cliente->id, 'slot_id' => $slot->id, 'week_start' => $weekPast, 'status' => 'confirmada']);
        Reservation::create(['user_id' => $cliente->id, 'slot_id' => $slot->id, 'week_start' => $weekFuture, 'status' => 'confirmada']);

        $this->assertEquals(2, Reservation::where('user_id', $cliente->id)->count());

        Sanctum::actingAs($admin);

        $resp = $this->deleteJson("/api/users/{$cliente->id}");
        $resp->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $cliente->id]);
        $this->assertEquals(0, Reservation::where('user_id', $cliente->id)->count());
        $this->assertDatabaseMissing('reservations', ['user_id' => $cliente->id]);
    }

    public function test_cliente_no_puede_eliminar_usuarios_403(): void
    {
        $cliente = $this->cliente();
        $other = User::create(['name' => 'Other', 'email' => 'other@test.test', 'password' => Hash::make('password'), 'role' => 'cliente', 'weekly_hours' => 1]);

        Sanctum::actingAs($cliente);

        // Intentar eliminar otro
        $r1 = $this->deleteJson("/api/users/{$other->id}");
        $r1->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $other->id]);

        // Intentar eliminar su propia cuenta
        $r2 = $this->deleteJson("/api/users/{$cliente->id}");
        $r2->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $cliente->id]);
    }

    public function test_eliminar_usuario_no_existe_404(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $resp = $this->deleteJson('/api/users/99999');
        $resp->assertStatus(404);
    }
}
