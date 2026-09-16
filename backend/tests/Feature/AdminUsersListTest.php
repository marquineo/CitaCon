<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class AdminUsersListTest extends TestCase
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

    private function cliente(string $email, int $hours = 3): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'cliente',
            'weekly_hours' => $hours,
        ]);
    }

    public function test_admin_puede_listar_clientes_con_campos_requeridos(): void
    {
        $admin = $this->admin();
        $c1 = $this->cliente('a@test.test', 3);
        $c2 = $this->cliente('b@test.test', 5);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/users');
        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['id', 'name', 'email', 'weekly_hours']]]);

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertNotContains($admin->id, $ids);

        // Verificar campos específicos
        $data = collect($response->json('data'))->firstWhere('id', $c1->id);
        $this->assertEquals($c1->email, $data['email']);
        $this->assertEquals(3, $data['weekly_hours']);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayNotHasKey('password', $data);
    }

    public function test_cliente_no_puede_listar_usuarios_recibe_403(): void
    {
        $cliente = $this->cliente('cliente@test.test', 3);
        $this->cliente('other@test.test', 2);

        Sanctum::actingAs($cliente);

        $response = $this->getJson('/api/users');
        $response->assertStatus(403);
    }

    public function test_lista_nunca_incluye_administradores(): void
    {
        $admin = $this->admin();
        $admin2 = User::create([
            'name' => 'Admin2',
            'email' => 'admin2@test.test',
            'password' => Hash::make('password'),
            'role' => 'administrador',
            'weekly_hours' => 0,
        ]);
        $cliente = $this->cliente('cliente@test.test', 4);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/users');
        $response->assertStatus(200);

        $roles = collect($response->json('data'))->pluck('role')->toArray();
        $this->assertNotContains('administrador', $roles);
        $this->assertContains('cliente', $roles);

        $emails = collect($response->json('data'))->pluck('email')->toArray();
        $this->assertNotContains($admin->email, $emails);
        $this->assertNotContains($admin2->email, $emails);
        $this->assertContains($cliente->email, $emails);
    }
}
