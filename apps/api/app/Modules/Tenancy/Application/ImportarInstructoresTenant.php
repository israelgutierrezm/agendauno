<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Importación masiva de instructores por CSV (R37), tenant-local. Espejo del
 * importador de miembros, pero los instructores son USUARIOS con rol instructor
 * (cuenta inactiva + token de activación), no personas. Valida fila por fila
 * (preview) y, al importar, es todo-o-nada: si alguna fila es inválida no crea
 * ninguna cuenta (rollback). Tras crear, envía la invitación de activación a cada
 * uno (igual que el alta individual). El correo es obligatorio (sin él no hay
 * forma de activar la cuenta) y debe ser único dentro del estudio.
 */
class ImportarInstructoresTenant
{
    /** Columnas reconocidas del CSV (el resto se ignora). */
    public const COLUMNAS = ['nombre', 'email'];

    public function __construct(private readonly EnviarActivacionTenant $enviarActivacion) {}

    /**
     * Valida las filas sin escribir. Devuelve el resultado por fila + un resumen.
     *
     * @param  list<array<string, string>>  $filas
     * @return array{resumen: array{total: int, validas: int, invalidas: int}, filas: list<array{fila: int, datos: array<string, mixed>, errores: list<string>}>}
     */
    public function analizar(array $filas): array
    {
        // Con los dados de baja: su correo es suyo (se reactivan invitándolos de nuevo).
        $existentes = Usuario::withTrashed()
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

            $email = $datos['email'] !== '' ? mb_strtolower($datos['email']) : null;
            if ($email !== null && $errores === []) {
                if ($existentes->has($email)) {
                    $errores[] = "Ya existe un usuario con el correo {$email}.";
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
     * Importa las filas todo-o-nada. Si alguna es inválida, no crea nada y devuelve
     * el análisis con `ok=false`. Si todas son válidas, crea las cuentas inactivas en
     * una transacción y, ya confirmadas, envía la invitación de activación a cada
     * instructor.
     *
     * @param  list<array<string, string>>  $filas
     * @return array{ok: bool, resumen: array{total: int, validas: int, invalidas: int}, filas: list<array{fila: int, datos: array<string, mixed>, errores: list<string>}>, creados?: int, activaciones?: list<array{email: string, token: string}>|null}
     */
    public function importar(array $filas, Estudio $estudio): array
    {
        $analisis = $this->analizar($filas);

        if ($analisis['resumen']['invalidas'] > 0 || $analisis['resumen']['total'] === 0) {
            return ['ok' => false] + $analisis;
        }

        /** @var list<string> $correos */
        $correos = DB::connection('tenant')->transaction(function () use ($analisis): array {
            $correos = [];
            foreach ($analisis['filas'] as $fila) {
                $usuario = Usuario::query()->create([
                    'name' => $fila['datos']['nombre'],
                    'email' => $fila['datos']['email'],
                    'rol' => 'instructor',
                    'roles' => ['instructor'],
                    'activo' => false,
                    'password' => null,
                ]);
                $correos[] = (string) $usuario->email;
            }

            return $correos;
        });

        // Fuera de la transacción: genera el token y encola la invitación por correo.
        $activaciones = [];
        foreach ($correos as $correo) {
            $token = $this->enviarActivacion->enviar($estudio, $correo);
            $activaciones[] = ['email' => $correo, 'token' => $token];
        }

        return [
            'ok' => true,
            'creados' => count($correos),
            'activaciones' => app()->environment('production') ? null : $activaciones,
        ] + $analisis;
    }

    /**
     * @param  array<string, string>  $cruda
     * @return array<string, string>
     */
    private function normalizar(array $cruda): array
    {
        $tomar = static fn (string $clave): string => trim((string) ($cruda[$clave] ?? ''));

        return [
            'nombre' => $tomar('nombre'),
            'email' => $tomar('email'),
        ];
    }

    /**
     * @param  array<string, string>  $datos
     * @return list<string>
     */
    private function validar(array $datos): array
    {
        $validador = Validator::make($datos, [
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ], [], [
            'nombre' => 'nombre',
            'email' => 'correo',
        ]);

        return array_values($validador->errors()->all());
    }
}
