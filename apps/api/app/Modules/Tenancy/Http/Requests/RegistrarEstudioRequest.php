<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\PerfilNegocio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RegistrarEstudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('slug')) {
            $merge['slug'] = Str::slug((string) $this->input('slug'));
        }
        // Lada de WhatsApp: default México (52) y solo dígitos (quita "+", espacios).
        $pais = preg_replace('/\D+/', '', (string) $this->input('contacto_whatsapp_pais', '52'));
        $merge['contacto_whatsapp_pais'] = ($pais === '' || $pais === null) ? '52' : $pais;
        $this->merge($merge);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Paso 1: el lugar.
            'nombre' => ['required', 'string', 'max:255'],
            // El enlace público (agendauno.mx/mi-estudio) se genera AUTOMÁTICAMENTE a
            // partir del nombre; el registrante no lo captura. Se acepta opcional por
            // compatibilidad (p. ej. un slug ya elegido), validado si viene.
            'slug' => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9-]+$/', 'unique:estudios,slug'],
            'perfil_negocio' => ['nullable', Rule::enum(PerfilNegocio::class)],
            // Paso 2: el nombre del propietario (desglosado; apellido materno y segundo nombre opcionales).
            'contacto_nombre' => ['required', 'string', 'max:120'],
            'contacto_segundo_nombre' => ['nullable', 'string', 'max:120'],
            'contacto_primer_apellido' => ['required', 'string', 'max:120'],
            'contacto_segundo_apellido' => ['nullable', 'string', 'max:120'],
            // Paso 3: contacto (WhatsApp con lada + correo para validar).
            'contacto_whatsapp_pais' => ['required', 'string', 'regex:/^\d{1,4}$/'],
            'contacto_telefono' => ['required', 'string', 'regex:/^[0-9 \-]{7,15}$/'],
            'contacto_email' => ['required', 'email', 'max:255'],
            'pais' => ['nullable', 'string', 'size:2'],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'zona_horaria' => ['nullable', 'timezone'],
            'acepta_terminos' => ['accepted'],
        ];
    }
}
