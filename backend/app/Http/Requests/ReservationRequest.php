<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

class ReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'slot_id' => ['required', 'exists:slots,id'],
            'week_start' => ['required', 'date', 'date_format:Y-m-d'],
            'user_id' => ['sometimes', 'exists:users,id'], // existe si se envía; restricción "solo admin" se aplica en ReservationController::store, no aquí (FR-013) — evita inducir a error
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $weekStart = $this->input('week_start');
            if (!$weekStart) return;

            try {
                $date = Carbon::parse($weekStart, 'Europe/Madrid');
                if (!$date->isMonday()) {
                    $validator->errors()->add('week_start', 'week_start debe ser un lunes (semana ISO).');
                }
            } catch (\Exception $e) {
                $validator->errors()->add('week_start', 'Formato de semana inválido.');
            }
        });
    }
}
