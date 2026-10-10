<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\CampoFormularioCaptacionTenant;
use App\Modules\Tenancy\Models\FormularioCaptacionTenant;
use App\Modules\Tenancy\TipoCampo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Los formularios de captación del negocio (ADR 0117). Siempre hay uno predeterminado
 * (el de «Pedir acceso» de su página): si el negocio no ha creado ninguno, se crea con
 * textos neutros la primera vez que se necesita. Cada formulario pide nombre y correo,
 * el teléfono si el negocio lo quiere y sus propias preguntas.
 */
class FormulariosCaptacionTenant
{
    /** Preguntas propias que admite un formulario. */
    public const MAX_CAMPOS = 15;

    /** El predeterminado (lo crea si no hay ninguno). */
    public function predeterminado(): FormularioCaptacionTenant
    {
        $formulario = FormularioCaptacionTenant::query()->where('predeterminado', true)->where('activo', true)->first()
            ?? FormularioCaptacionTenant::query()->where('activo', true)->orderBy('id')->first();
        if ($formulario instanceof FormularioCaptacionTenant) {
            return $formulario;
        }

        return FormularioCaptacionTenant::query()->create([
            'nombre' => 'Pedir acceso',
            'titulo' => 'Quiero ser parte',
            'descripcion' => 'Déjanos tus datos y te contactamos para darte acceso.',
            'mensaje_confirmacion' => 'Recibimos tus datos. Te contactaremos pronto.',
            'pide_telefono' => true,
            'telefono_obligatorio' => false,
            'predeterminado' => true,
            'activo' => true,
        ]);
    }

    /**
     * Crea o actualiza un formulario con sus preguntas (las que no vienen se quitan; las
     * respuestas ya recibidas guardan su etiqueta).
     *
     * @param  array{nombre: string, titulo: string, descripcion?: string|null, mensaje_confirmacion?: string|null, pide_telefono?: bool, telefono_obligatorio?: bool, predeterminado?: bool, activo?: bool, campos?: list<array{id?: string|null, etiqueta: string, tipo: string, obligatorio?: bool, opciones?: list<string>|null}>}  $datos
     */
    public function guardar(?FormularioCaptacionTenant $formulario, array $datos): FormularioCaptacionTenant
    {
        $campos = $datos['campos'] ?? [];
        if (count($campos) > self::MAX_CAMPOS) {
            throw ValidationException::withMessages(['campos' => ['Un formulario admite hasta '.self::MAX_CAMPOS.' preguntas.']]);
        }
        foreach ($campos as $i => $campo) {
            $opciones = array_values(array_filter(array_map('trim', $campo['opciones'] ?? []), static fn (string $o): bool => $o !== ''));
            if ($campo['tipo'] === TipoCampo::Seleccion->value && count($opciones) < 2) {
                throw ValidationException::withMessages(["campos.{$i}.opciones" => ['Una pregunta de opción necesita al menos dos opciones.']]);
            }
        }

        return DB::connection('tenant')->transaction(function () use ($formulario, $datos, $campos): FormularioCaptacionTenant {
            $valores = [
                'nombre' => trim($datos['nombre']),
                'titulo' => trim($datos['titulo']),
                'descripcion' => $datos['descripcion'] ?? null,
                'mensaje_confirmacion' => $datos['mensaje_confirmacion'] ?? null,
                'pide_telefono' => $datos['pide_telefono'] ?? true,
                'telefono_obligatorio' => ($datos['pide_telefono'] ?? true) && ($datos['telefono_obligatorio'] ?? false),
                'activo' => $datos['activo'] ?? true,
            ];
            $formulario = $formulario instanceof FormularioCaptacionTenant
                ? tap($formulario)->update($valores)
                : FormularioCaptacionTenant::query()->create($valores);

            // Uno solo es el de «Pedir acceso»; el primero que se crea lo es.
            $esPredeterminado = (bool) ($datos['predeterminado'] ?? false)
                || ! FormularioCaptacionTenant::query()->where('predeterminado', true)->whereKeyNot($formulario->getKey())->exists();
            if ($esPredeterminado) {
                FormularioCaptacionTenant::query()->whereKeyNot($formulario->getKey())->update(['predeterminado' => false]);
            }
            $formulario->forceFill(['predeterminado' => $esPredeterminado])->save();

            $conservar = [];
            foreach ($campos as $orden => $campo) {
                $existente = isset($campo['id']) && $campo['id'] !== ''
                    ? CampoFormularioCaptacionTenant::query()->where('formulario_id', $formulario->getKey())->where('ulid', $campo['id'])->first()
                    : null;
                $valoresCampo = [
                    'formulario_id' => $formulario->getKey(),
                    'etiqueta' => trim($campo['etiqueta']),
                    'tipo' => $campo['tipo'],
                    'obligatorio' => $campo['obligatorio'] ?? false,
                    'opciones' => $campo['tipo'] === TipoCampo::Seleccion->value
                        ? array_values(array_filter(array_map('trim', $campo['opciones'] ?? []), static fn (string $o): bool => $o !== ''))
                        : null,
                    'orden' => $orden,
                ];
                $guardado = $existente instanceof CampoFormularioCaptacionTenant
                    ? tap($existente)->update($valoresCampo)
                    : CampoFormularioCaptacionTenant::query()->create($valoresCampo);
                $conservar[] = $guardado->getKey();
            }
            CampoFormularioCaptacionTenant::query()->where('formulario_id', $formulario->getKey())->whereNotIn('id', $conservar)->delete();

            return $formulario->refresh()->load('campos');
        });
    }
}
