<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Carbon\Carbon;

class UserController extends Controller
{
    use AuthorizesRequests;

    /**
     * Listado de clientes — solo admin, solo lectura.
     * No existía tarea explícita en tasks.md para GET /api/users (hueco detectado).
     * Se implementa como endpoint mínimo para que admin-quota pueda listar clientes.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'weekly_hours' => $user->weekly_hours,
            ]
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);
        // Solo clientes, ordenados por email, con campos mínimos para UI
        $users = User::where('role', 'cliente')->orderBy('email')->get(['id', 'name', 'email', 'weekly_hours', 'role']);
        return response()->json(['data' => $users]);
    }

    public function quota(Request $request): JsonResponse
    {
        $authUser = $request->user();

        // Determinar semana: ?week_start o lunes actual Europe/Madrid
        $weekStart = $request->input('week_start');
        if (!$weekStart) {
            $weekStart = Carbon::now('Europe/Madrid')->startOfWeek(Carbon::MONDAY)->toDateString();
        } else {
            // Validar formato y normalizar a lunes (si no es lunes, usar tal cual pero el conteo será por esa fecha exacta)
            try {
                $weekStart = Carbon::parse($weekStart, 'Europe/Madrid')->toDateString();
            } catch (\Exception $e) {
                $weekStart = Carbon::now('Europe/Madrid')->startOfWeek(Carbon::MONDAY)->toDateString();
            }
        }

        // Determinar usuario objetivo: cliente siempre ve su propio quota, admin puede ver otro vía ?user_id
        $targetUser = $authUser;
        if ($authUser->role === 'administrador' && $request->filled('user_id')) {
            $targetUser = User::find($request->input('user_id'));
            if (!$targetUser) {
                return response()->json(['message' => 'No encontrado.'], 404);
            }
        }
        // Si es cliente e intenta ?user_id de otro, se ignora y ve el suyo (no filtrar existencia)

        $assigned = (int) $targetUser->weekly_hours;
        $used = Reservation::where('user_id', $targetUser->id)
            ->where('week_start', $weekStart)
            ->count();
        $remaining = $assigned - $used;

        return response()->json([
            'data' => [
                'assigned' => $assigned,
                'used' => $used,
                'remaining' => $remaining,
                'week_start' => $weekStart,
            ]
        ]);
    }

    public function updateWeeklyHours(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $validated = $request->validate([
            'weekly_hours' => ['required', 'integer', 'min:0', 'max:50'],
        ]);
        $user->update(['weekly_hours' => $validated['weekly_hours']]);
        return response()->json(['data' => $user]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'weekly_hours' => ['required', 'integer', 'min:0', 'max:50'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => \Illuminate\Support\Facades\Hash::make($validated['password']),
            'role' => 'cliente',
            'weekly_hours' => $validated['weekly_hours'],
        ]);

        return response()->json(['data' => $user], 201);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        // Hard DELETE en cascada: reservations se eliminan por FK cascadeOnDelete
        // Sin registro de auditoría, consistente con hard delete sin trazabilidad
        $user->delete();

        return response()->json(['message' => 'Usuario eliminado.'], 200);
    }
}
