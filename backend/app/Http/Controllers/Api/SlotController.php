<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SlotRequest;
use App\Models\Slot;
use App\Services\SlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Carbon\Carbon;

class SlotController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private SlotService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Slot::query();

        if ($request->filled('week_start')) {
            $weekStart = $request->input('week_start');
            // Añadir ocupación por semana y si franja ya pasó para esa semana
            $slots = $query->get()->map(function (Slot $slot) use ($weekStart) {
                $slot->occupation = $slot->reservations()->where('week_start', $weekStart)->count();
                $slot->is_past = $slot->realDateTimeFor($weekStart)->isPast();
                return $slot;
            });
            return response()->json(['data' => $slots]);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Slot $slot): JsonResponse
    {
        return response()->json(['data' => $slot]);
    }

    public function store(SlotRequest $request): JsonResponse
    {
        $this->authorize('create', Slot::class);

        try {
            $slot = Slot::create($request->validated());
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json(['message' => 'Ya existe una franja para ' . $this->formatSlot($request)], 422);
            }
            throw $e;
        }

        return response()->json(['data' => $slot], 201);
    }

    public function update(SlotRequest $request, Slot $slot): JsonResponse
    {
        $this->authorize('update', $slot);

        try {
            $updated = $this->service->update($slot, $request->validated());
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json(['message' => 'Ya existe una franja para ' . $this->formatSlot($request)], 422);
            }
            throw $e;
        }

        return response()->json(['data' => $updated], 200);
    }

    public function destroy(Request $request, Slot $slot): JsonResponse
    {
        $this->authorize('delete', $slot);
        $slot->delete();
        return response()->json(['message' => 'Franja eliminada.'], 200);
    }

    public function block(Request $request, Slot $slot): JsonResponse
    {
        $this->authorize('block', $slot);
        $blocked = $this->service->block($slot);
        return response()->json(['message' => 'Franja bloqueada.', 'data' => $blocked], 200);
    }

    public function unblock(Request $request, Slot $slot): JsonResponse
    {
        $this->authorize('block', $slot);
        $unblocked = $this->service->unblock($slot);
        return response()->json(['message' => 'Franja desbloqueada.', 'data' => $unblocked], 200);
    }

    private function isDuplicateException(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'slots_day_time_unique') || $e->getCode() === '23000';
    }

    private function formatSlot($request): string
    {
        $days = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
        $day = $days[$request->input('day_of_week')] ?? $request->input('day_of_week');
        $time = $request->input('start_time');
        return "$day $time";
    }
}
