<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\RegistroNoConfirmable;
use App\Modules\Tenancy\Mail\CorreoConfirmarRegistro;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VerificacionRegistroTenant;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registro de un alumno desde la página pública del negocio (1.5 de la fase 1).
 *
 * Escribir un correo no demuestra que sea de quien se registra. Por eso:
 * - con un correo nuevo, la cuenta y su ficha se crean al momento (y entra);
 * - si el correo ya es de una ficha (p. ej. la dio de alta recepción) o de una
 *   cuenta dada de baja, NO se liga ni se reactiva todavía: se manda un enlace a ese
 *   correo y solo al abrirlo se liga a su historial (membresías, créditos, datos).
 *
 * La contraseña elegida se guarda ya cifrada en la solicitud y se aplica al
 * confirmar; hasta entonces la cuenta anterior no cambia.
 */
class RegistrarAlumnoTenant
{
    public function __construct(
        private readonly BajasTenant $bajas,
        private readonly RegistrarEventoTenant $eventos,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * @param  array{nombre: string, primer_apellido: string|null, email: string, password: string}  $datos
     */
    public function registrar(Estudio $estudio, array $datos): RegistroDeAlumno
    {
        $email = self::normalizar($datos['email']);

        if (Usuario::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Ya existe una cuenta con ese correo en este estudio. Inicia sesión.'],
            ]);
        }

        if (! $this->correoConHistorial($email)) {
            [$usuario, $persona] = $this->crearCuenta($estudio, $email, $datos['nombre'], $datos['primer_apellido'], $datos['password']);

            return RegistroDeAlumno::creado($usuario, $persona);
        }

        $token = Str::random(48);
        DB::connection('tenant')->transaction(function () use ($email, $datos, $token): void {
            // Solo vale el último enlace pedido.
            VerificacionRegistroTenant::query()->where('email', $email)->whereNull('usada_en')->delete();
            VerificacionRegistroTenant::query()->create([
                'email' => $email,
                'nombre' => $datos['nombre'],
                'primer_apellido' => $datos['primer_apellido'],
                'password' => Hash::make($datos['password']),
                'token_hash' => hash('sha256', $token),
                'expira_en' => now()->addHours($this->parametros->entero('cuentas.horas_confirmar_registro')),
            ]);
        });

        Mail::to($email)->queue(new CorreoConfirmarRegistro((string) $estudio->nombre, (string) $estudio->slug, $email, $token));

        return RegistroDeAlumno::porConfirmar($email, $token);
    }

    /**
     * Abre el enlace del correo: liga la cuenta a su historial (reactivando lo que
     * estuviera dado de baja) y entra.
     */
    public function confirmar(Estudio $estudio, string $email, string $token): RegistroDeAlumno
    {
        $email = self::normalizar($email);

        [$usuario, $persona] = DB::connection('tenant')->transaction(function () use ($estudio, $email, $token): array {
            $verificacion = VerificacionRegistroTenant::query()
                ->where('email', $email)
                ->whereNull('usada_en')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $verificacion instanceof VerificacionRegistroTenant
                || now()->greaterThan($verificacion->expira_en)
                || ! hash_equals($verificacion->token_hash, hash('sha256', $token))) {
                throw new RegistroNoConfirmable('El enlace para confirmar tu registro no es válido o ya venció. Regístrate de nuevo.');
            }
            if (Usuario::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                throw new RegistroNoConfirmable('Ya existe una cuenta con ese correo en este estudio. Inicia sesión.');
            }

            $verificacion->update(['usada_en' => now()]);

            return $this->crearCuenta($estudio, $email, $verificacion->nombre, $verificacion->primer_apellido, $verificacion->password);
        });

        return RegistroDeAlumno::creado($usuario, $persona);
    }

    /**
     * ¿El correo ya es de alguien en el negocio (una ficha o una cuenta dada de baja)?
     */
    private function correoConHistorial(string $email): bool
    {
        return Usuario::withTrashed()->whereRaw('lower(email) = ?', [$email])->exists()
            || PersonaTenant::withTrashed()->whereRaw('lower(email) = ?', [$email])->exists();
    }

    /**
     * Crea la cuenta y su ficha, o —ya confirmado el correo— liga la cuenta a la ficha
     * que tenía y reactiva lo que estuviera dado de baja. `$password` puede llegar ya
     * cifrada (de la solicitud confirmada): el cast `hashed` no la cifra dos veces.
     *
     * @return array{0: Usuario, 1: PersonaTenant}
     */
    private function crearCuenta(Estudio $estudio, string $email, string $nombre, ?string $primerApellido, string $password): array
    {
        $nombreCompleto = trim($nombre.' '.($primerApellido ?? ''));

        return DB::connection('tenant')->transaction(function () use ($estudio, $email, $nombre, $primerApellido, $password, $nombreCompleto): array {
            $datosUsuario = [
                'name' => $nombreCompleto !== '' ? $nombreCompleto : $nombre,
                'email' => $email,
                'password' => $password,
                'activo' => true,
            ];

            $anterior = Usuario::withTrashed()->whereRaw('lower(email) = ?', [$email])->first();
            $persona = ($anterior instanceof Usuario
                ? PersonaTenant::withTrashed()->where('usuario_id', $anterior->getKey())->first()
                : null) ?? PersonaTenant::withTrashed()->whereRaw('lower(email) = ?', [$email])->first();
            // Una ficha que ya es de otra cuenta activa no se toca.
            if ($persona instanceof PersonaTenant && $persona->usuario_id !== null
                && $persona->usuario_id !== $anterior?->getKey()
                && Usuario::query()->whereKey($persona->usuario_id)->exists()) {
                $persona = null;
            }
            if ($persona instanceof PersonaTenant && $persona->trashed()) {
                $this->bajas->reactivarPersona($persona, null, 'Confirmó su correo al registrarse.');
            }

            if ($anterior instanceof Usuario) {
                if ($anterior->trashed()) {
                    $this->bajas->reactivarUsuario($anterior, null, 'Confirmó su correo al registrarse.');
                }
                $anterior->forceFill($datosUsuario)->save();
                $usuario = $anterior;
            } else {
                $usuario = Usuario::query()->create([...$datosUsuario, 'rol' => 'miembro', 'roles' => ['miembro']]);
            }

            if ($persona instanceof PersonaTenant) {
                $persona->update(['usuario_id' => $usuario->getKey(), 'activo' => true, 'archivado' => false]);
            } else {
                $persona = PersonaTenant::query()->create([
                    'nombre' => $nombre,
                    'primer_apellido' => $primerApellido,
                    'email' => $email,
                    'tipo' => TipoPersonaTenant::Miembro->value,
                    'activo' => true,
                    'es_facturable' => true,
                    'archivado' => false,
                    'usuario_id' => $usuario->getKey(),
                ]);
            }

            // Correo de bienvenida (plantilla `cuenta.creada`) con el enlace a su cuenta.
            $this->eventos->registrar('cuenta.creada', 'persona', (string) $persona->ulid, [
                'persona_id' => (string) $persona->ulid,
                'enlace' => rtrim((string) config('turnouno.url_app'), '/').'/entrar?estudio='.rawurlencode((string) $estudio->slug),
            ]);

            return [$usuario, $persona];
        });
    }

    private static function normalizar(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
