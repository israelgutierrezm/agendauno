<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\CampoFormulario;
use App\Modules\Tenancy\Models\Formulario;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\RespuestaFormulario;

/**
 * Los formularios que le tocan a una persona (por su tipo), cada uno con la
 * definición de sus campos y lo que ya respondió. Lo usan su expediente (el
 * personal) y su propia cuenta (el alumno).
 */
class FormulariosDePersonaTenant
{
    /**
     * Formularios activos que aplican a su tipo (miembro/instructor) con su respuesta.
     *
     * @return list<array<string, mixed>>
     */
    public function de(PersonaTenant $persona): array
    {
        $respuestas = RespuestaFormulario::query()
            ->where('persona_id', $persona->getKey())
            ->get()
            ->keyBy('formulario_id');

        return Formulario::query()
            ->with('campos')
            ->where('activo', true)
            ->whereIn('aplica_a', [$persona->tipo->value, 'todos'])
            ->orderBy('nombre')
            ->get()
            ->map(static function (Formulario $f) use ($respuestas): array {
                $respuesta = $respuestas->get($f->getKey());
                $valores = $respuesta instanceof RespuestaFormulario ? ($respuesta->valores ?? []) : [];

                return [
                    'id' => $f->ulid,
                    'nombre' => $f->nombre,
                    'descripcion' => $f->descripcion,
                    'respondido_en' => $respuesta instanceof RespuestaFormulario
                        ? $respuesta->updated_at?->toIso8601String()
                        : null,
                    // La definición de cada campo con su valor: sirve para leer las
                    // respuestas y para llenarlas desde el expediente.
                    'campos' => $f->campos
                        ->map(static fn (CampoFormulario $c): array => [
                            'id' => $c->ulid,
                            'etiqueta' => $c->etiqueta,
                            'tipo' => $c->tipo->value,
                            'obligatorio' => $c->obligatorio,
                            'opciones' => $c->opciones,
                            'valor' => $valores[$c->ulid] ?? null,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }
}
