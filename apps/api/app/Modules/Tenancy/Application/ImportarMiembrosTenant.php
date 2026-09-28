<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Importación masiva de miembros por CSV (R37), tenant-local. Valida fila por fila
 * (preview) y, al importar, es todo-o-nada: si alguna fila es inválida no escribe
 * nada (rollback). Detecta correos duplicados dentro del archivo y contra los ya
 * existentes en el estudio. El nombre se importa desglosado (R-personas).
 */
class ImportarMiembrosTenant
{
    /**
     * Valida las filas sin escribir. Devuelve el resultado por fila + un resumen.
     *
     * @param  list<array<string, string>>  $filas
     * @return array{resumen: array{total: int, validas: int, invalidas: int}, filas: list<array{fila: int, datos: array<string, mixed>, errores: list<string>}>}
     */
    public function analizar(array $filas): array
    {
        // Con los dados de baja: su correo es suyo (se reactivan desde Miembros).
        $existentes = PersonaTenant::withTrashed()
            ->whereNotNull('email')
            ->pluck('email')
            ->map(static fn ($e): string => mb_strtolower((string) $e))
            ->flip();

        $vistos = [];
        $salida = [];
        $validas = 0;

        foreach ($filas as $indice => $cruda) {
            $datos = $this->normalizar($cruda);
            $errores = $this->validar($datos);

            $email = is_string($datos['email'] ?? null) ? mb_strtolower($datos['email']) : null;
            if ($email !== null && $errores === []) {
                if ($existentes->has($email)) {
                    $errores[] = "Ya existe un miembro con el correo {$email}.";
                } elseif (isset($vistos[$email])) {
                    $errores[] = "El correo {$email} está repetido en el archivo.";
                }
            }
            if ($email !== null) {
                $vistos[$email] = true;
            }

            if ($errores === []) {
                $validas++;
            }

            $salida[] = ['fila' => $indice + 1, 'datos' => $datos, 'errores' => $errores];
        }

        return [
            'resumen' => ['total' => count($filas), 'validas' => $validas, 'invalidas' => count($filas) - $validas],
            'filas' => $salida,
        ];
    }

    /**
     * Importa las filas todo-o-nada. Si alguna es inválida, no escribe nada y
     * devuelve el análisis con `ok=false`. Si todas son válidas, crea en una
     * transacción y devuelve `ok=true` con el conteo.
     *
     * @param  list<array<string, string>>  $filas
     * @return array{ok: bool, resumen: array{total: int, validas: int, invalidas: int}, filas: list<array{fila: int, datos: array<string, mixed>, errores: list<string>}>, creados?: int}
     */
    public function importar(array $filas): array
    {
        $analisis = $this->analizar($filas);

        if ($analisis['resumen']['invalidas'] > 0 || $analisis['resumen']['total'] === 0) {
            return ['ok' => false] + $analisis;
        }

        $creados = DB::connection('tenant')->transaction(function () use ($analisis): int {
            $n = 0;
            foreach ($analisis['filas'] as $fila) {
                PersonaTenant::query()->create([
                    'nombre' => $fila['datos']['nombre'],
                    'segundo_nombre' => $fila['datos']['segundo_nombre'] ?: null,
                    'primer_apellido' => $fila['datos']['primer_apellido'] ?: null,
                    'segundo_apellido' => $fila['datos']['segundo_apellido'] ?: null,
                    'email' => $fila['datos']['email'] ?: null,
                    'tipo' => $fila['datos']['tipo'] ?: TipoPersonaTenant::Miembro->value,
                    'activo' => true,
                    'es_facturable' => $fila['datos']['es_facturable'],
                    'archivado' => false,
                ]);
                $n++;
            }

            return $n;
        });

        return ['ok' => true, 'creados' => $creados] + $analisis;
    }

    /**
     * @param  array<string, string>  $cruda
     * @return array<string, mixed>
     */
    private function normalizar(array $cruda): array
    {
        $tomar = static fn (string $clave): string => trim((string) ($cruda[$clave] ?? ''));
        $opcional = static fn (string $clave): ?string => ($v = trim((string) ($cruda[$clave] ?? ''))) !== '' ? $v : null;

        return [
            'nombre' => $tomar('nombre'),
            'segundo_nombre' => $opcional('segundo_nombre'),
            'primer_apellido' => $opcional('primer_apellido'),
            'segundo_apellido' => $opcional('segundo_apellido'),
            'email' => $opcional('email'),
            'tipo' => $opcional('tipo'),
            'es_facturable' => $this->interpretarBool($tomar('es_facturable')),
        ];
    }

    private function interpretarBool(string $valor): bool
    {
        // Vacío = facturable por defecto; solo valores negativos explícitos lo apagan.
        return ! in_array(mb_strtolower($valor), ['0', 'false', 'no', 'n'], true);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return list<string>
     */
    private function validar(array $datos): array
    {
        $validador = Validator::make($datos, [
            'nombre' => ['required', 'string', 'max:255'],
            'segundo_nombre' => ['nullable', 'string', 'max:255'],
            'primer_apellido' => ['nullable', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'tipo' => ['nullable', 'in:miembro,instructor,staff'],
        ], [], [
            'nombre' => 'nombre',
            'email' => 'correo',
            'tipo' => 'tipo',
        ]);

        return array_values($validador->errors()->all());
    }
}
