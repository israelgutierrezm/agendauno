<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrearMiembroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza cadenas vacías a null para que `nullable` aplique (y no se cuele
     * un "" como valor "repetido" en las validaciones de unicidad).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->filled('email') ? $this->input('email') : null,
            'celular' => $this->filled('celular') ? $this->input('celular') : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'segundo_nombre' => ['nullable', 'string', 'max:255'],
            'primer_apellido' => ['nullable', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            // Correo y teléfono son datos primarios: únicos dentro del estudio (tenant).
            // Si son de alguien dado de baja, el alta lo resuelve (reactiva o pregunta).
            'email' => ['nullable', 'email', 'max:255', Rule::unique(PersonaTenant::class, 'email')->whereNull('deleted_at')],
            'celular' => ['nullable', 'string', 'max:40', Rule::unique(PersonaTenant::class, 'celular')->whereNull('deleted_at')],
            // El celular es de alguien dado de baja pero es otra persona: se le quita.
            'liberar_celular' => ['sometimes', 'boolean'],
            // El cliente pidió los avisos por WhatsApp (ADR 0069).
            'acepta_whatsapp' => ['sometimes', 'boolean'],
            'tipo' => ['nullable', 'in:miembro,instructor,staff'],
            'es_facturable' => ['nullable', 'boolean'],
            'sucursal_id' => ['nullable', 'string'],
            // Opcionales: fecha de nacimiento y género (lista breve).
            ...DatosPersonales::reglas(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe una persona con ese correo en este estudio.',
            'celular.unique' => 'Ya existe una persona con ese teléfono en este estudio.',
            ...DatosPersonales::mensajes(),
        ];
    }
}
