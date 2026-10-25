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
 * (preview) y, al importar, sin `parcial` es todo-o-nada: si alguna fila es inválida
 * o está en conflicto no crea ninguna cuenta (rollback). El correo es obligatorio
 * (sin él no hay forma de activar la cuenta) y debe ser único dentro del estudio.
 *
 * Migración guiada (C07): la fila de alguien que ya es usuario con ese correo y ese
 * nombre queda `existente` (repetir el archivo no lo duplica). La invitación por correo
 * sale solo si quien importa lo confirma (`invitar`); si no, las cuentas quedan sin
 * activar y se invita después desde Equipo.
 */
class ImportarInstructoresTenant
{
    public function __construct(private readonly EnviarActivacionTenant $enviarActivacion) {}

    /**
     * Valida las filas sin escribir. Devuelve el resultado por fila + un resumen.
     *
     * @param  list<array<string, string>>  $filas
     * @return array{resumen: array{total: int, validas: int, invalidas: int, rechazadas: int, conflictos: int, existentes: int}, filas: list<array{fila: int, datos: array<string, mixed>, errores: list<string>, estado: string}>}
     */
    public function analizar(array $filas): array
    {
        // Con los dados de baja: su correo es suyo (se reactivan invitándolos de nuevo).
        $existentes = Usuario::withTrashed()
            ->whereNotNull('email')
            ->get(['email', 'name', 'deleted_at'])
            ->keyBy(static fn (Usuario $u): string => mb_strtolower((string) $u->email));

        $vistos = [];
        $salida = [];

        foreach ($filas as $indice => $cruda) {
            $datos = $this->normalizar($cruda);
            $errores = $this->validar($datos);
            $estado = $errores === [] ? ImportarMiembrosTenant::VALIDA : ImportarMiembrosTenant::INVALIDA;

            $email = $datos['email'] !== '' ? mb_strtolower($datos['email']) : null;
            if ($email !== null && $estado === ImportarMiembrosTenant::VALIDA) {
                $previo = $existentes->get($email);
                if ($previo instanceof Usuario) {
                    if ($previo->deleted_at === null && ImportarMiembrosTenant::nombre($previo->name) === ImportarMiembrosTenant::nombre($datos['nombre'])) {
                        $estado = ImportarMiembrosTenant::EXISTENTE;
                    } else {
                        $estado = ImportarMiembrosTenant::CONFLICTO;
                        $errores[] = "Ya existe un usuario con el correo {$email}.";
                    }
                } elseif (isset($vistos[$email])) {
                    $estado = ImportarMiembrosTenant::CONFLICTO;
                    $errores[] = "El correo {$email} está repetido en el archivo.";
                }
            }
            if ($email !== null) {
                $vistos[$email] = true;
            }

            $salida[] = ['fila' => $indice + 1, 'datos' => $datos, 'errores' => $errores, 'estado' => $estado];
        }

        return ['resumen' => ImportarMiembrosTenant::resumen($salida), 'filas' => $salida];
    }

    /**
     * Importa las filas válidas. Sin `$parcial` es todo-o-nada: si alguna es inválida
     * o está en conflicto, no crea nada y devuelve el análisis con `ok=false`. Crea las
     * cuentas inactivas en una transacción y, solo con `$invitar`, envía la invitación
     * de activación a cada instructor nuevo.
     *
     * @param  list<array<string, string>>  $filas
     * @return array{ok: bool, resumen: array{total: int, validas: int, invalidas: int, rechazadas: int, conflictos: int, existentes: int}, filas: list<array{fila: int, datos: array<string, mixed>, errores: list<string>, estado: string}>, creados?: int, invitados?: int, activaciones?: list<array{email: string, token: string}>|null}
     */
    public function importar(array $filas, Estudio $estudio, bool $invitar = true, bool $parcial = false): array
    {
        $analisis = $this->analizar($filas);

        if ($analisis['resumen']['total'] === 0 || (! $parcial && $analisis['resumen']['invalidas'] > 0)) {
            return ['ok' => false] + $analisis;
        }

        /** @var list<string> $correos */
        $correos = DB::connection('tenant')->transaction(function () use ($analisis): array {
            $correos = [];
            foreach ($analisis['filas'] as $fila) {
                if ($fila['estado'] !== ImportarMiembrosTenant::VALIDA) {
                    continue;
                }
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

        // Fuera de la transacción y solo si se confirmó: genera el token y encola la
        // invitación por correo.
        $activaciones = [];
        if ($invitar) {
            foreach ($correos as $correo) {
                $token = $this->enviarActivacion->enviar($estudio, $correo);
                $activaciones[] = ['email' => $correo, 'token' => $token];
            }
        }

        return [
            'ok' => true,
            'creados' => count($correos),
            'invitados' => count($activaciones),
            'activaciones' => ! app()->environment(['local', 'testing']) ? null : $activaciones,
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
