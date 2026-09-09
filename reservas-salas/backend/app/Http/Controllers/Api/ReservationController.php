<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReservationRequest;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReservationController extends Controller
{
    public function __construct(private ReservationService $service) {}

    public function index()
    {
        return response()->json(['data' => []]);
    }

    public function store(ReservationRequest $request): JsonResponse
    {
        $authUser = $request->user();
        $isAdmin = $authUser->role === 'administrador';

        // Validación de user_id ya hecha en ReservationRequest (exists), pero restricción "solo admin" vive aquí
        $targetUserId = null;
        if ($request->filled('user_id')) {
            if (!$isAdmin) {
                // Cliente intenta suplantar: ignorar silenciosamente, usar su propio id
                $targetUserId = null;
            } else {
                $targetUserId = (int) $request->input('user_id');
            }
        }

        // Si es cliente, forzar auth id; si es admin sin user_id, también usa su propio id (crea para sí)
        // La lógica de efectivo ya está en el servicio, pero aquí documentamos la intención
        try {
            $reservation = $this->service->create(
                slotId: (int) $request->input('slot_id'),
                weekStart: $request->input('week_start'),
                authUser: $authUser,
                targetUserId: $targetUserId
            );
        } catch (HttpException $e) {
            // 409 franja completa / bloqueada
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (ValidationException $e) {
            // 422 cupo semanal / 0h / semana pasada
            // Mantener formato ValidationException con errors
            throw $e;
        }

        return response()->json(['data' => $reservation], 201);
    }

    public function show()
    {
        return response()->json(['data' => null]);
    }

    public function destroy()
    {
        return response()->json(['message' => 'stub']);
    }
}
