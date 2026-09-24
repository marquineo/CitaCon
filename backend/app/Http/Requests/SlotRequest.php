<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'integer', 'between:1,5'],
            'start_time' => ['required', 'regex:/^(0?[7-9]|1[0-9]|2[0-1]):00:00$/', 'date_format:H:i:s'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'trainer' => ['nullable', 'in:Carlos,Alicia'],
            'status' => ['sometimes', 'in:abierta,bloqueada'],
        ];
    }

    public function messages(): array
    {
        return [
            'day_of_week.between' => 'Franja fuera del horario permitido (L-V 7:00-22:00, 1h).',
            'start_time.regex' => 'Franja debe ser en punto (07:00:00 ... 21:00:00).',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('start_time') && preg_match('/^\d{1,2}:\d{2}$/', $this->start_time)) {
            $this->merge(['start_time' => $this->start_time . ':00']);
        }
    }
}
