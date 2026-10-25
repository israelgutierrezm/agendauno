<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DispositivoPaseWallet;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PaseWallet;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Los pases de la cartera que ya guardaron se actualizan solos (ADR 0195): cuando
 * cambia lo que muestran (su plan, hasta cuándo vale, su saldo o su código), se avisa a
 * los teléfonos con el pase en Apple Wallet (APNs, con el certificado del tipo de pase;
 * el teléfono pide el pase nuevo al servicio web) y se actualiza el objeto en Google
 * Wallet (su API, con la cuenta de servicio).
 *
 * - Revisa negocio por negocio, en la base de cada uno, solo a quien guardó su pase.
 * - Un teléfono que Apple ya no reconoce (410) se olvida. Un objeto que nunca se guardó
 *   en Google (404) no es error.
 */
class ActualizarPasesWallet
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly PasesWalletTenant $wallet,
    ) {}

    /** Revisa todos los pases guardados; devuelve cuántos cambiaron. */
    public function revisar(): int
    {
        $cambiados = 0;
        PaseWallet::query()->select('estudio_id')->distinct()->pluck('estudio_id')
            ->each(function (int $estudioId) use (&$cambiados): void {
                $estudio = Estudio::query()->find($estudioId);
                if (! $estudio instanceof Estudio || ! $this->gestor->baseDeDatosExiste($estudio)) {
                    return;
                }
                try {
                    $cambiados += (int) $this->gestor->ejecutarEn($estudio, fn (): int => $this->revisarNegocio($estudio));
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $cambiados;
    }

    private function revisarNegocio(Estudio $estudio): int
    {
        if (! app(PaseAccesoTenant::class)->walletPermitido()) {
            return 0;
        }
        $cambiados = 0;
        PaseWallet::query()->where('estudio_id', $estudio->getKey())->with('dispositivos')->chunkById(100, function ($pases) use ($estudio, &$cambiados): void {
            $personas = PersonaTenant::query()->whereIn('ulid', $pases->pluck('persona_ulid')->all())->get()->keyBy('ulid');
            foreach ($pases as $pase) {
                $persona = $personas->get($pase->persona_ulid);
                if (! $persona instanceof PersonaTenant) {
                    continue;
                }
                $huella = PasesWalletTenant::huella($this->wallet->contenido($estudio, $persona));
                if ($huella === $pase->huella) {
                    continue;
                }
                $pase->forceFill(['huella' => $huella, 'cambio_en' => now()])->save();
                $cambiados++;
                $this->avisar($estudio, $persona, $pase);
            }
        });

        return $cambiados;
    }

    /**
     * Renueva el pase (perdió el teléfono, alguien copió su pase): un código nuevo y un
     * número de serie nuevo, así que el pase anterior deja de servir del todo. Recepción
     * y el kiosco ya no aceptan su código; Apple Wallet ya no lo actualiza (su token es
     * de la serie vieja y sus teléfonos registrados se olvidan) y su objeto de Google
     * Wallet queda inactivo. El pase nuevo se agrega otra vez desde su cuenta.
     */
    public function renovar(Estudio $estudio, PersonaTenant $persona): void
    {
        app(PaseAccesoTenant::class)->codigoDeWallet($persona, renovar: true);

        $pase = PaseWallet::query()->where('estudio_id', $estudio->getKey())->where('persona_ulid', $persona->ulid)->first();
        if (! $pase instanceof PaseWallet) {
            return;
        }
        $objetoAnterior = $pase->en_google ? PasesWalletTenant::objetoDeLaSerie($pase->serie) : null;
        DB::connection($pase->getConnectionName())->transaction(function () use ($pase, $estudio, $persona): void {
            $pase->dispositivos()->delete();
            $pase->forceFill([
                'serie' => PasesWalletTenant::serie($estudio, $persona).'-'.bin2hex(random_bytes(4)),
                'huella' => null,
                'cambio_en' => now(),
                'en_google' => false,
            ])->save();
        });

        if ($objetoAnterior !== null && $this->wallet->googleDisponible()) {
            try {
                $this->desactivarGoogle($objetoAnterior);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Avisa del cambio a sus teléfonos (Apple) y actualiza su objeto (Google). */
    public function avisar(Estudio $estudio, PersonaTenant $persona, PaseWallet $pase): void
    {
        if ($this->wallet->appleDisponible()) {
            foreach ($pase->dispositivos as $dispositivo) {
                try {
                    $this->avisarApple($dispositivo);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
        if ($pase->en_google && $this->wallet->googleDisponible()) {
            try {
                $this->actualizarGoogle($estudio, $persona);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Un aviso vacío por APNs (HTTP/2) al tema del tipo de pase: el teléfono pide al
     * servicio web los pases que cambiaron.
     */
    private function avisarApple(DispositivoPaseWallet $dispositivo): void
    {
        $c = (array) config('agendauno.wallet.apple');
        $respuesta = Http::withOptions([
            'version' => 2.0,
            'cert' => ($c['clave'] ?? '') !== '' ? [(string) $c['certificado'], (string) $c['clave']] : (string) $c['certificado'],
        ])
            ->withHeaders(['apns-topic' => (string) $c['pass_type_id'], 'apns-push-type' => 'background'])
            ->timeout(10)
            ->withBody('{}', 'application/json')
            ->post(rtrim((string) ($c['apns_url'] ?? 'https://api.push.apple.com'), '/').'/3/device/'.rawurlencode($dispositivo->push_token));

        // El teléfono ya no tiene el pase o ya no recibe avisos.
        if ($respuesta->status() === 410) {
            $dispositivo->delete();

            return;
        }
        if (! $respuesta->successful()) {
            throw new RuntimeException('Apple no aceptó el aviso del pase ('.$respuesta->status().').');
        }
    }

    /** Actualiza el objeto del pase en Google Wallet con lo que muestra ahora. */
    private function actualizarGoogle(Estudio $estudio, PersonaTenant $persona): void
    {
        $objeto = $this->wallet->objetoDeGoogle($estudio, $persona);
        $respuesta = Http::withToken($this->tokenGoogle())
            ->timeout(10)
            ->patch('https://walletobjects.googleapis.com/walletobjects/v1/genericObject/'.rawurlencode((string) $objeto['id']), $objeto);

        // Nunca lo guardó en su cartera: no hay nada que actualizar.
        if ($respuesta->status() === 404) {
            return;
        }
        if (! $respuesta->successful()) {
            throw new RuntimeException('Google Wallet no aceptó el cambio del pase ('.$respuesta->status().').');
        }
    }

    /** El objeto de un pase renovado queda inactivo en Google Wallet: ya no se usa. */
    private function desactivarGoogle(string $objeto): void
    {
        $respuesta = Http::withToken($this->tokenGoogle())
            ->timeout(10)
            ->patch('https://walletobjects.googleapis.com/walletobjects/v1/genericObject/'.rawurlencode($objeto), ['state' => 'INACTIVE']);

        // Nunca lo guardó en su cartera: no hay nada que desactivar.
        if ($respuesta->status() === 404) {
            return;
        }
        if (! $respuesta->successful()) {
            throw new RuntimeException('Google Wallet no desactivó el pase anterior ('.$respuesta->status().').');
        }
    }

    /** Un token de acceso de la cuenta de servicio (dura una hora; se guarda 50 minutos). */
    private function tokenGoogle(): string
    {
        return (string) Cache::remember('wallet-google-token', now()->addMinutes(50), function (): string {
            $c = (array) config('agendauno.wallet.google');
            /** @var array{client_email?: string, private_key?: string} $cuenta */
            $cuenta = (array) json_decode((string) file_get_contents((string) $c['cuenta_servicio']), true);
            $ahora = now()->getTimestamp();
            $assertion = PasesWalletTenant::jwt([
                'iss' => (string) ($cuenta['client_email'] ?? ''),
                'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $ahora,
                'exp' => $ahora + 3600,
            ], (string) ($cuenta['private_key'] ?? ''));
            $respuesta = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
            $token = (string) $respuesta->json('access_token', '');
            if (! $respuesta->successful() || $token === '') {
                throw new RuntimeException('Google no dio acceso a la cuenta de servicio de Wallet.');
            }

            return $token;
        });
    }
}
