<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReservationRequest;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Carbon\Carbon;

class ReservationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private ReservationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // Cliente solo ve suyas; admin puede filtrar por ?user_id
        $query = Reservation::with('slot');
        if ($user->role !== 'administrador') {
            $query->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        } else {
            // Admin sin filtro user_id ve todas las reservas de todos los clientes (decisión confirmada).
        }

        if ($request->filled('week_start')) {
            $query->where('week_start', $request->input('week_start'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(ReservationRequest $request): JsonResponse
    {
        $authUser = $request->user();
        $isAdmin = $authUser->role === 'administrador';

        $targetUserId = null;
        if ($request->filled('user_id')) {
            if (!$isAdmin) {
                $targetUserId = null;
            } else {
                $targetUserId = (int) $request->input('user_id');
            }
        }

        try {
            $reservation = $this->service->create(
                slotId: (int) $request->input('slot_id'),
                weekStart: $request->input('week_start'),
                authUser: $authUser,
                targetUserId: $targetUserId
            );
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json(['data' => $reservation], 201);
    }

    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        try {
            $this->authorize('view', $reservation);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            // Un único lugar para la regla: ReservationPolicy::view — 404 indistinguible para no filtrar existencia
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['data' => $reservation]);
    }

    public function destroy(Request $request, Reservation $reservation): JsonResponse
    {
        $user = $request->user();

        // Verificar Policy::delete (propietario o admin) — pero devolver 404 indistinguible para no filtrar existencia (FR-008)
        if ($user->cannot('delete', $reservation)) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        // Validar que la franja no haya pasado: week_start (lunes) + offset day_of_week + start_time > now()
        $reservation->loadMissing('slot');
        $slot = $reservation->slot;
        if ($slot) {
            $slotDateTime = $slot->realDateTimeFor($reservation->week_start->format('Y-m-d'));
            if ($slotDateTime->isPast()) {
                throw ValidationException::withMessages([
                    'week_start' => ['No se puede cancelar una franja ya iniciada/pasada.'],
                ]);
            }
        }

        // Hard DELETE en transacción, sin lockForUpdate (no protege recurso compartido limitado)
        DB::transaction(function () use ($reservation) {
            $reservation->delete();
        });

        return response()->json(['message' => 'Reserva cancelada.'], 200);
    }
}
