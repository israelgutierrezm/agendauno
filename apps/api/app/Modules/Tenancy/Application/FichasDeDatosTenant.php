<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\NuevaVersionSinConfirmar;
use App\Modules\Tenancy\Models\CampoFormulario;
use App\Modules\Tenancy\Models\Formulario;
use App\Modules\Tenancy\Models\RespuestaFormulario;
use App\Modules\Tenancy\TipoCampo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edición de una ficha de datos sin perder lo ya respondido (ADR 0150). Se guarda
 * completa (nombre, a quién aplica y sus preguntas en orden). Si ya tiene respuestas:
 * una pregunta quitada se archiva (no se borra), cambiar el tipo de una pregunta crea
 * otra (la anterior queda archivada con sus valores) y cualquier cambio a las
 * preguntas sube la versión. Cada respuesta guarda la versión y las preguntas con que
 * se contestó ({@see self::preguntasActuales()}).
 */
class FichasDeDatosTenant
{
    public const MAX_PREGUNTAS = 50;

    /**
     * @param  array{nombre: string, descripcion?: string|null, aplica_a?: string|null, campos?: list<array{id?: string|null, etiqueta: string, tipo: string, obligatorio?: bool|null, opciones?: list<string|null>|null}>}  $datos
     */
    public function guardar(Formulario $formulario, array $datos, bool $confirmado = true): Formulario
    {
        $campos = $datos['campos'] ?? [];
        if (count($campos) > self::MAX_PREGUNTAS) {
            throw ValidationException::withMessages(['campos' => ['Una ficha admite hasta '.self::MAX_PREGUNTAS.' preguntas.']]);
        }
        foreach ($campos as $i => $campo) {
            if ($campo['tipo'] === TipoCampo::Seleccion->value && count(self::opciones($campo['opciones'] ?? [])) < 2) {
                throw ValidationException::withMessages(["campos.{$i}.opciones" => ['Una pregunta de opción necesita al menos dos opciones.']]);
            }
        }

        return DB::connection('tenant')->transaction(function () use ($formulario, $datos, $campos, $confirmado): Formulario {
            // Dos personas editando a la vez no se pisan la versión.
            $bloqueado = Formulario::query()->whereKey($formulario->getKey())->lockForUpdate()->firstOrFail();
            $conRespuestas = RespuestaFormulario::query()->where('formulario_id', $bloqueado->getKey())->exists();
            $antes = self::huella($bloqueado);

            $bloqueado->fill([
                'nombre' => trim($datos['nombre']),
                'descripcion' => $datos['descripcion'] ?? null,
                'aplica_a' => $datos['aplica_a'] ?? $bloqueado->aplica_a,
            ]);

            $conservar = [];
            foreach ($campos as $orden => $campo) {
                $valores = [
                    'etiqueta' => trim($campo['etiqueta']),
                    'tipo' => $campo['tipo'],
                    'obligatorio' => (bool) ($campo['obligatorio'] ?? false),
                    'opciones' => $campo['tipo'] === TipoCampo::Seleccion->value ? self::opciones($campo['opciones'] ?? []) : null,
                    'orden' => $orden,
                ];
                $existente = isset($campo['id']) && $campo['id'] !== ''
                    ? $bloqueado->campos()->where('ulid', $campo['id'])->first()
                    : null;
                if ($existente instanceof CampoFormulario && $conRespuestas && $existente->tipo->value !== $campo['tipo']) {
                    // Otro tipo de dato: la anterior se queda (archivada) con lo respondido.
                    $existente->forceFill(['archivado_en' => now()])->save();
                    $existente = null;
                }
                $guardado = $existente instanceof CampoFormulario
                    ? tap($existente)->update($valores)
                    : $bloqueado->campos()->create($valores);
                $conservar[] = $guardado->getKey();
            }

            $quitadas = CampoFormulario::query()
                ->where('formulario_id', $bloqueado->getKey())
                ->whereNull('archivado_en')
                ->whereNotIn('id', $conservar);
            // Con respuestas se archivan (siguen leyéndose en ellas); sin, se borran.
            $conRespuestas ? $quitadas->update(['archivado_en' => now()]) : $quitadas->delete();

            $bloqueado->unsetRelation('campos');
            if ($conRespuestas && self::huella($bloqueado) !== $antes) {
                // Una versión nueva se publica a sabiendas (ADR 0166); si no, se deshace.
                if (! $confirmado) {
                    throw new NuevaVersionSinConfirmar(
                        RespuestaFormulario::query()->where('formulario_id', $bloqueado->getKey())->count(),
                        $bloqueado->version + 1,
                    );
                }
                $bloqueado->version = $bloqueado->version + 1;
            }
            $bloqueado->save();

            return $bloqueado->refresh()->load('campos');
        });
    }

    /**
     * Sube la versión si la ficha ya tiene respuestas (al agregarle una pregunta suelta).
     */
    public function cambioDePreguntas(Formulario $formulario): void
    {
        if (RespuestaFormulario::query()->where('formulario_id', $formulario->getKey())->exists()) {
            Formulario::query()->whereKey($formulario->getKey())->increment('version');
        }
    }

    /**
     * Las preguntas vigentes como se guardan en cada respuesta.
     *
     * @return list<array{id: string, etiqueta: string, tipo: string, opciones: list<string>|null}>
     */
    public static function preguntasActuales(Formulario $formulario): array
    {
        return $formulario->campos
            ->map(static fn (CampoFormulario $c): array => [
                'id' => (string) $c->ulid,
                'etiqueta' => $c->etiqueta,
                'tipo' => $c->tipo->value,
                'opciones' => $c->opciones,
            ])
            ->values()
            ->all();
    }

    /**
     * Qué preguntas tiene la ficha y cómo (para saber si cambiaron).
     */
    private static function huella(Formulario $formulario): string
    {
        return (string) json_encode($formulario->campos()->get()->map(static fn (CampoFormulario $c): array => [
            $c->ulid, $c->etiqueta, $c->tipo->value, $c->obligatorio, $c->opciones,
        ])->all());
    }

    /**
     * @param  array<int, string|null>  $opciones
     * @return list<string>
     */
    private static function opciones(array $opciones): array
    {
        return array_values(array_filter(
            array_map(static fn (?string $o): string => trim((string) $o), $opciones),
            static fn (string $o): bool => $o !== '',
        ));
    }
}
