<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $email, string $password, string $role = 'cliente', int $hours = 3): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'weekly_hours' => $hours,
        ]);
    }

    public function test_login_valido_devuelve_200_con_token_y_datos_usuario(): void
    {
        $this->createUser('valid@test.test', 'secret123', 'cliente', 3);

        $response = $this->postJson('/api/login', [
            'email' => 'valid@test.test',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'token',
            'data' => ['id', 'name', 'email', 'role'],
        ]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertIsString($response->json('token'));
        $response->assertJsonPath('data.email', 'valid@test.test');
        $response->assertJsonPath('data.role', 'cliente');
    }

    public function test_login_credenciales_incorrectas_devuelve_401(): void
    {
        $this->createUser('user@test.test', 'correct123', 'cliente', 3);

        $response = $this->postJson('/api/login', [
            'email' => 'user@test.test',
            'password' => 'wrongpass',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Credenciales incorrectas.']);
    }

    public function test_ruta_protegida_sin_token_devuelve_401(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_ruta_protegida_con_bearer_token_valido_devuelve_200(): void
    {
        $this->createUser('bearer@test.test', 'secret123', 'cliente', 3);

        $login = $this->postJson('/api/login', [
            'email' => 'bearer@test.test',
            'password' => 'secret123',
        ]);
        $login->assertStatus(200);
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJsonPath('data.email', 'bearer@test.test');
    }

    public function test_logout_revoca_token_y_posterior_uso_devuelve_401(): void
    {
        $this->createUser('logout@test.test', 'secret123', 'cliente', 3);

        $login = $this->postJson('/api/login', [
            'email' => 'logout@test.test',
            'password' => 'secret123',
        ]);
        $login->assertStatus(200);
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        // Verificar token válido antes de logout
        $before = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/user');
        $before->assertStatus(200);

        $this->app['auth']->forgetGuards();

        // Logout revoca token actual
        $logout = $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/logout');
        $logout->assertStatus(200);

        // Limpiar estado de autenticación en memoria entre peticiones dentro del mismo test
        // (en producción cada HTTP es un proceso aislado; en tests el contenedor reutiliza la guard)
        $this->app['auth']->forgetGuards();

        // Posterior uso del mismo token debe dar 401
        $after = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/user');
        $after->assertStatus(401);
    }

    public function test_login_valida_email_y_password_requeridos(): void
    {
        $r1 = $this->postJson('/api/login', ['email' => '', 'password' => '']);
        $r1->assertStatus(422);
        $r1->assertJsonValidationErrors(['email', 'password']);

        $r2 = $this->postJson('/api/login', ['email' => 'not-an-email', 'password' => 'secret123']);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['email']);
    }
}
