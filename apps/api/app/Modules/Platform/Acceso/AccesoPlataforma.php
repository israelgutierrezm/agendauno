<?php

declare(strict_types=1);

namespace App\Modules\Platform\Acceso;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Entrada del superadministrador (ADR 0126), en dos pasos:
 *
 * 1. Correo, contraseña y el token de la plataforma (`PLATFORM_ADMIN_TOKEN`): los tres
 *    deben coincidir; el error nunca dice cuál falló. Se manda un código de 6 dígitos
 *    al correo del operador (se guarda solo su huella; vence y cuenta intentos).
 * 2. El código: abre una sesión con su propio token (se guarda solo su huella; vence).
 *
 * El token de la plataforma por sí solo deja de abrir la API en producción
 * (`plataforma.token_directo`); fuera de producción sigue sirviendo para pruebas y
 * scripts.
 */
class AccesoPlataforma
{
    /**
     * Paso 1. Devuelve el desafío y su código (el código solo para mostrarlo fuera de
     * producción), o null si algo no coincide.
     *
     * @return array{desafio: DesafioPlataforma, codigo: string, correo: string}|null
     */
    public function iniciar(string $email, string $password, string $token, ?string $ip): ?array
    {
        $esperado = config('agendauno.plataforma.token');
        $administrador = AdministradorPlataforma::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();

        // Se compara todo siempre (mismo trabajo con o sin cuenta): sin pistas de cuál falló.
        $tokenValido = is_string($esperado) && $esperado !== '' && hash_equals($esperado, $token);
        $passwordValida = Hash::check($password, $administrador instanceof AdministradorPlataforma ? $administrador->password : Hash::make(Str::random(16)));
        if (! $tokenValido || ! $passwordValida || ! $administrador instanceof AdministradorPlataforma || ! $administrador->activo) {
            return null;
        }

        $codigo = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $minutos = max(1, (int) config('agendauno.plataforma.minutos_codigo', 10));

        // Un desafío vigente a la vez: los anteriores sin usar dejan de servir.
        DesafioPlataforma::query()->where('administrador_id', $administrador->getKey())->whereNull('usado_en')
            ->update(['usado_en' => now()]);
        $desafio = DesafioPlataforma::query()->create([
            'administrador_id' => $administrador->getKey(),
            'codigo_hash' => self::huella($codigo),
            'expira_en' => now()->addMinutes($minutos),
            'ip' => $ip,
        ]);

        Mail::to($administrador->email)->send(new CorreoCodigoPlataforma($administrador->nombre, $codigo, $minutos));

        return ['desafio' => $desafio, 'codigo' => $codigo, 'correo' => self::enmascarar($administrador->email)];
    }

    /**
     * Paso 2. Devuelve el token de la sesión nueva, o null si el código no sirve
     * (equivocado, vencido, ya usado o con demasiados intentos).
     *
     * @return array{token: string, sesion: SesionPlataforma, administrador: AdministradorPlataforma}|null
     */
    public function verificar(string $desafioUlid, string $codigo, ?string $ip, ?string $agente): ?array
    {
        return DB::transaction(function () use ($desafioUlid, $codigo, $ip, $agente): ?array {
            $desafio = DesafioPlataforma::query()->where('ulid', $desafioUlid)->lockForUpdate()->first();
            $maximo = max(1, (int) config('agendauno.plataforma.intentos_codigo', 5));
            if (! $desafio instanceof DesafioPlataforma || $desafio->usado_en !== null
                || $desafio->expira_en->isPast() || $desafio->intentos >= $maximo) {
                return null;
            }
            if (! hash_equals($desafio->codigo_hash, self::huella(trim($codigo)))) {
                $desafio->intentos++;
                $desafio->save();

                return null;
            }

            $administrador = $desafio->administrador;
            if (! $administrador instanceof AdministradorPlataforma || ! $administrador->activo) {
                return null;
            }
            $desafio->usado_en = now();
            $desafio->save();

            $token = Str::random(64);
            $sesion = SesionPlataforma::query()->create([
                'administrador_id' => $administrador->getKey(),
                'token_hash' => hash('sha256', $token),
                'expira_en' => now()->addHours(max(1, (int) config('agendauno.plataforma.horas_sesion', 12))),
                'ip' => $ip,
                'agente' => $agente !== null ? Str::limit($agente, 250, '') : null,
            ]);
            $administrador->update(['ultimo_acceso_en' => now()]);

            return ['token' => $token, 'sesion' => $sesion, 'administrador' => $administrador];
        });
    }

    /** El operador de una sesión vigente con ese token, o null. */
    public function autenticar(string $token): ?AdministradorPlataforma
    {
        $sesion = SesionPlataforma::query()->where('token_hash', hash('sha256', $token))
            ->whereNull('cerrada_en')
            ->where('expira_en', '>', now())
            ->first();
        $administrador = $sesion?->administrador;
        if (! $sesion instanceof SesionPlataforma || ! $administrador instanceof AdministradorPlataforma || ! $administrador->activo) {
            return null;
        }
        // Sin escribir en cada petición: basta con saber el último uso por minuto.
        if ($sesion->ultimo_uso_en === null || $sesion->ultimo_uso_en->lt(now()->subMinute())) {
            $sesion->forceFill(['ultimo_uso_en' => Carbon::now()])->save();
        }

        return $administrador;
    }

    /** Cierra la sesión de ese token. */
    public function salir(string $token): void
    {
        SesionPlataforma::query()->where('token_hash', hash('sha256', $token))->whereNull('cerrada_en')
            ->update(['cerrada_en' => now()]);
    }

    private static function huella(string $codigo): string
    {
        return hash_hmac('sha256', $codigo, (string) config('app.key'));
    }

    /** «ad***@agendauno.mx»: a dónde se mandó, sin mostrar el correo completo. */
    private static function enmascarar(string $email): string
    {
        [$usuario, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($usuario, 0, 2).'***@'.$dominio;
    }
}
