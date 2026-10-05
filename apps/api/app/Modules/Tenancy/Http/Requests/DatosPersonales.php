<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\GeneroPersona;
use Illuminate\Validation\Rule;

/**
 * Reglas de la fecha de nacimiento y el género (opcionales), las mismas para el alta,
 * la edición por el equipo y «Mi perfil».
 */
final class DatosPersonales
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function reglas(): array
    {
        return [
            'fecha_nacimiento' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:today'],
            'genero' => ['sometimes', 'nullable', Rule::enum(GeneroPersona::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mensajes(): array
    {
        return [
            'fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'fecha_nacimiento.after' => 'Revisa el año de la fecha de nacimiento.',
            'fecha_nacimiento.date_format' => 'Escribe la fecha de nacimiento como AAAA-MM-DD.',
            'genero.enum' => 'Elige un género de la lista.',
        ];
    }
}
