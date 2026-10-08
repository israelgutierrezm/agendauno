<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ImportarInstructoresTenant;
use App\Modules\Tenancy\Application\ImportarMiembrosTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\PlanCitasSaas;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Importación masiva por CSV (R37), tenant-local. Ofrece PREVIEW (valida sin
 * escribir, con errores por fila) e IMPORT (todo-o-nada con rollback). Recursos:
 * miembros (personas) e instructores (usuarios con rol instructor). Opera sobre la
 * BD del estudio resuelto.
 */
class ImportacionesTenantController
{
    public function __construct(
        private readonly ImportarMiembrosTenant $miembros,
        private readonly ImportarInstructoresTenant $instructores,
        // Tope de filas por archivo: lo fija el superadmin (ADR 0047).
        private readonly ParametrosTenant $parametros,
    ) {}

    public function previewMiembros(Request $request): JsonResponse
    {
        $filas = $this->parsear($request, ['nombre'], $this->parametros->entero('importaciones.max_filas'));

        return response()->json(['data' => $this->miembros->analizar($filas)]);
    }

    public function importarMiembros(Request $request): JsonResponse
    {
        $filas = $this->parsear($request, ['nombre'], $this->parametros->entero('importaciones.max_filas'));

        $resultado = $this->miembros->importar($filas);

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
        $filas = $this->parsear($request, ['nombre', 'email'], $this->parametros->entero('importaciones.max_filas'));
        // Todos deben caber en el plan del negocio (ADR 0107).
        app(PlanCitasSaas::class)->exigirCupo($this->estudioDe($request), count($filas));

        $resultado = $this->instructores->importar($filas, $this->estudioDe($request));

        // Todo-o-nada: si hubo filas inválidas no se creó ninguna cuenta -> 422.
        return response()->json(['data' => $resultado], $resultado['ok'] ? 201 : 422);
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
