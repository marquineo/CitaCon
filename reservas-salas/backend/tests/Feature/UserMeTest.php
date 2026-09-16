<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class UserMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_autenticado_get_api_user_devuelve_200_con_datos_correctos(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.test',
            'password' => Hash::make('password123'),
            'role' => 'cliente',
            'weekly_hours' => 5,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.email', $user->email);
        $response->assertJsonPath('data.name', $user->name);
        $response->assertJsonPath('data.role', $user->role);
        $response->assertJsonPath('data.weekly_hours', $user->weekly_hours);
    }

    public function test_sin_autenticar_get_api_user_devuelve_401(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }
}
