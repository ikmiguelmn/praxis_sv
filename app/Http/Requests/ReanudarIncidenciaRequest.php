<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReanudarIncidenciaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado_destino' => ['nullable', Rule::in(['E06', 'E07', 'E08', 'E09', 'E10', 'E11'])],
            'observacion' => ['required', 'string', 'min:10', 'max:1000'],
            'fecha_programada' => ['nullable', 'required_with:hora_inicio,hora_fin', 'date_format:Y-m-d', 'after_or_equal:today'],
            'hora_inicio' => ['nullable', 'required_with:fecha_programada,hora_fin', 'date_format:H:i'],
            'hora_fin' => ['nullable', 'required_with:fecha_programada,hora_inicio', 'date_format:H:i', 'after:hora_inicio'],
        ];
    }
}
