<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CargarSaldosAperturaTenant;
use App\Modules\Tenancy\Application\CupoProfesionales;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ImportarInstructoresTenant;
use App\Modules\Tenancy\Application\ImportarMiembrosTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Importación masiva por CSV (R37), tenant-local. Ofrece PREVIEW (valida sin
 * escribir, con errores por fila) e IMPORT (todo-o-nada con rollback, o solo las
 * válidas con `parcial`). Recursos: miembros (personas), instructores (usuarios con rol
 * instructor) y saldos de apertura al migrar (C07). Cada carga queda en la bitácora
 * con su resumen y su duración, para medir la incorporación. Opera sobre la
 * BD del estudio resuelto.
 */
class ImportacionesTenantController
{
    public function __construct(
        private readonly ImportarMiembrosTenant $miembros,
        private readonly ImportarInstructoresTenant $instructores,
        // Tope de filas por archivo: lo fija el superadmin (ADR 0047).
        private readonly ParametrosTenant $parametros,
        private readonly CargarSaldosAperturaTenant $saldos,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function previewMiembros(Request $request): JsonResponse
    {
        $filas = $this->parsear($request, ['nombre'], $this->parametros->entero('importaciones.max_filas'));

        return response()->json(['data' => $this->miembros->analizar($filas)]);
    }

    public function importarMiembros(Request $request): JsonResponse
    {
        $inicio = hrtime(true);
        $filas = $this->parsear($request, ['nombre'], $this->parametros->entero('importaciones.max_filas'));
        $parcial = $request->boolean('parcial');

        $resultado = $this->miembros->importar($filas, $parcial);
        $this->anotar($request, 'importacion.miembros', $resultado, $inicio, ['parcial' => $parcial, 'creados' => $resultado['creados'] ?? 0]);

        // Todo-o-nada: si hubo filas inválidas no se escribió nada (rollback) -> 422.
        return response()->json(['data' => $resultado], $resultado['ok'] ? 201 : 422);
    }

    public function previewInstructores(Request $request): JsonResponse
    {
        $filas = $this->parsear($request, ['nombre', 'email'], $this->parametros->entero('importaciones.max_filas'));

        return response()->json(['data' => $this->instructores->analizar($filas)]);
    }

    public function importarInstructores(Request $request): JsonResponse
    {
        $inicio = hrtime(true);
        // Las invitaciones por correo salen solo si quien importa lo confirma (C07).
        $request->validate(['invitar' => ['required', 'boolean']]);
        $invitar = $request->boolean('invitar');
        $parcial = $request->boolean('parcial');
        $filas = $this->parsear($request, ['nombre', 'email'], $this->parametros->entero('importaciones.max_filas'));
        // Los nuevos deben caber en el plan del negocio (ADR 0107), aunque al mismo tiempo
        // se invite a alguien más.
        $estudio = $this->estudioDe($request);
        $nuevos = $this->instructores->analizar($filas)['resumen']['validas'];
        $resultado = app(CupoProfesionales::class)->sumar($estudio, $nuevos, fn (): array => $this->instructores->importar($filas, $estudio, $invitar, $parcial));
        $this->anotar($request, 'importacion.instructores', $resultado, $inicio, [
            'parcial' => $parcial, 'creados' => $resultado['creados'] ?? 0, 'invitados' => $resultado['invitados'] ?? 0,
        ]);

        // Todo-o-nada: si hubo filas inválidas no se creó ninguna cuenta -> 422.
        return response()->json(['data' => $resultado], $resultado['ok'] ? 201 : 422);
    }

    /**
     * Saldos de apertura (C07): lo que cada alumno traía de otro sistema al corte
     * acordado. Valida sin escribir.
     */
    public function previewSaldos(Request $request): JsonResponse
    {
        $this->exigirApertura($request);
        $datos = $this->datosDeApertura($request);
        $filas = $this->parsear($request, ['email', 'plan'], $this->parametros->entero('importaciones.max_filas'));

        return response()->json(['data' => $this->saldos->analizar($filas, $datos['corte'], $this->usuario($request))]);
    }

    /**
     * Carga los saldos de apertura: sin venta ni pago, con el corte y la evidencia en el
     * libro de créditos y en la bitácora.
     */
    public function importarSaldos(Request $request): JsonResponse
    {
        $inicio = hrtime(true);
        $this->exigirApertura($request);
        $datos = $this->datosDeApertura($request);
        $parcial = $request->boolean('parcial');
        $filas = $this->parsear($request, ['email', 'plan'], $this->parametros->entero('importaciones.max_filas'));

        $resultado = $this->saldos->importar($filas, $datos['corte'], $datos['evidencia'], $this->usuario($request), $parcial);
        $this->anotar($request, 'importacion.saldos', $resultado, $inicio, [
            'parcial' => $parcial, 'cargados' => $resultado['cargados'] ?? 0, 'corte' => $datos['corte'],
        ], $datos['evidencia']);

        return response()->json(['data' => $resultado], $resultado['ok'] ? 201 : 422);
    }

    /**
     * @return array{corte: string, evidencia: string}
     */
    private function datosDeApertura(Request $request): array
    {
        // El corte es el de una migración real: hoy o antes, y no más atrás de lo que
        // permite la plataforma (`importaciones.dias_maximos_corte_saldos`).
        $hoy = app(FechasNegocioTenant::class)->hoy();
        $dias = app(CargarSaldosAperturaTenant::class)->diasMaximosCorte();
        $minimo = CarbonImmutable::parse($hoy)->subDays($dias)->toDateString();
        $validado = $request->validate([
            // El momento acordado para dejar de operar saldos en el otro sistema.
            'corte' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$hoy, 'after_or_equal:'.$minimo],
            // De dónde salen los saldos (p. ej. «reporte de saldos exportado el 30/09»).
            'evidencia' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'corte.required' => 'Indica la fecha de corte acordada.',
            'corte.date_format' => 'Escribe la fecha de corte como AAAA-MM-DD.',
            'corte.before_or_equal' => 'El corte no puede ser después de hoy.',
            'corte.after_or_equal' => "El corte no puede ser de hace más de {$dias} días.",
            'evidencia.required' => 'Indica de dónde salen los saldos (el reporte del otro sistema).',
        ]);

        return ['corte' => (string) $validado['corte'], 'evidencia' => trim((string) $validado['evidencia'])];
    }

    /**
     * Cargar saldos da planes y créditos sin venta ni pago: además de gestionar planes,
     * exige gestionar créditos y dar cortesías (es dar un plan sin cobrarlo).
     */
    private function exigirApertura(Request $request): void
    {
        $usuario = $this->usuario($request);
        abort_unless(
            $usuario?->puede('creditos.gestionar') === true && $usuario->puede('membresias.cortesia'),
            403,
            'Cargar saldos de apertura da planes sin cobrarlos: hace falta el permiso de cortesías.',
        );
    }

    private function usuario(Request $request): ?Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');

        return $usuario instanceof Usuario ? $usuario : null;
    }

    /**
     * Deja la carga en la bitácora con su resumen y cuánto tardó (para medir la
     * incorporación: aceptadas, rechazadas, conflictos, ya cargadas).
     *
     * @param  array<string, mixed>  $resultado
     * @param  array<string, mixed>  $extra
     */
    private function anotar(Request $request, string $accion, array $resultado, int|float $inicio, array $extra, ?string $motivo = null): void
    {
        $this->auditoria->registrar(
            $this->usuario($request),
            $accion,
            'importacion',
            null,
            null,
            [
                'ok' => (bool) ($resultado['ok'] ?? false),
                'resumen' => $resultado['resumen'] ?? null,
                'duracion_ms' => (int) round((hrtime(true) - $inicio) / 1_000_000),
                ...$extra,
            ],
            $motivo,
        );
    }

    private function estudioDe(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }

    /**
     * Lee el CSV subido en `archivo` a filas asociativas por su encabezado.
     *
     * @param  list<string>  $requeridas  Columnas que el encabezado debe traer.
     * @return list<array<string, string>>
     */
    private function parsear(Request $request, array $requeridas, int $max): array
    {
        $request->validate([
            'archivo' => ['required', 'file', 'max:2048'],
        ]);

        /** @var UploadedFile $archivo */
        $archivo = $request->file('archivo');

        $manejador = fopen($archivo->getRealPath(), 'r');
        if ($manejador === false) {
            throw ValidationException::withMessages(['archivo' => ['No se pudo leer el archivo.']]);
        }

        try {
            $encabezado = fgetcsv($manejador);
            if ($encabezado === false) {
                throw ValidationException::withMessages(['archivo' => ['El archivo está vacío.']]);
            }

            $columnas = array_map($this->normalizarEncabezado(...), $encabezado);
            foreach ($requeridas as $requerida) {
                if (! in_array($requerida, $columnas, true)) {
                    throw ValidationException::withMessages([
                        'archivo' => ["Falta la columna obligatoria \"{$requerida}\"."],
                    ]);
                }
            }

            $filas = [];
            while (($cruda = fgetcsv($manejador)) !== false) {
                // Omite filas totalmente vacías.
                if (count(array_filter($cruda, static fn ($v): bool => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                if (count($filas) >= $max) {
                    throw ValidationException::withMessages([
                        'archivo' => ['El archivo excede el máximo de '.$max.' filas.'],
                    ]);
                }

                $filas[] = $this->combinar($columnas, $cruda);
            }

            return $filas;
        } finally {
            fclose($manejador);
        }
    }

    private function normalizarEncabezado(?string $valor): string
    {
        // Minúsculas, sin espacios ni BOM inicial.
        $limpio = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $valor) ?? '');

        return str_replace(' ', '_', mb_strtolower($limpio));
    }

    /**
     * @param  list<string>  $columnas
     * @param  list<string|null>  $valores
     * @return array<string, string>
     */
    private function combinar(array $columnas, array $valores): array
    {
        $fila = [];
        foreach ($columnas as $i => $columna) {
            if ($columna === '') {
                continue;
            }
            $fila[$columna] = (string) ($valores[$i] ?? '');
        }

        return $fila;
    }
}
