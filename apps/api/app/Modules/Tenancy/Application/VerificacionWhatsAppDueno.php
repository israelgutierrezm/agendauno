<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\PlantillasWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Models\VerificacionWhatsApp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Verificación del WhatsApp del dueño al registrar su negocio (ADR 0070): se le manda
 * un código de 6 dígitos con la plantilla de autenticación; al confirmarlo recibe un
 * comprobante que presenta al crear el negocio (un solo uso). Solo si el
 * superadministrador encendió WhatsApp con los dueños.
 *
 * Cada código cuesta: hay espera entre envíos, tope por número por hora y tope diario
 * de toda la plataforma (parámetros de plataforma); el registro además pide reCAPTCHA.
 */
class VerificacionWhatsAppDueno
{
    /** Igual que la vigencia con que se registró la plantilla en Meta. */
    private const MINUTOS_CODIGO = 10;

    private const MAX_INTENTOS = 5;

    private const SEGUNDOS_ENTRE_ENVIOS = 60;

    /** Tiempo para terminar el registro después de verificar. */
    private const MINUTOS_COMPROBANTE = 60;

    public function __construct(
        private readonly ClienteWhatsApp $whatsapp,
        private readonly ParametrosTenant $parametros,
        private readonly AlertasPlataforma $alertas,
    ) {}

    public function disponible(): bool
    {
        return $this->whatsapp->activoParaDuenos();
    }

    /**
     * El número del registro (lada y número) como lo pide WhatsApp.
     */
    public static function telefono(string $lada, string $numero): ?string
    {
        return TelefonoWhatsApp::normalizar('+'.$lada.' '.$numero, $lada);
    }

    public function enviarCodigo(string $telefono, ?string $ip): void
    {
        if (! $this->disponible()) {
            throw self::rechazo('contacto_telefono', 'La verificación por WhatsApp no está disponible.');
        }

        $ultimo = VerificacionWhatsApp::query()->where('telefono', $telefono)->latest('id')->first();
        if ($ultimo instanceof VerificacionWhatsApp && $ultimo->created_at?->gt(now()->subSeconds(self::SEGUNDOS_ENTRE_ENVIOS))) {
            throw self::rechazo('contacto_telefono', 'Espera un minuto antes de pedir otro código.');
        }
        $enLaHora = VerificacionWhatsApp::query()->where('telefono', $telefono)->where('created_at', '>=', now()->subHour())->count();
        if ($enLaHora >= $this->parametros->entero('whatsapp.codigos_por_numero_hora')) {
            throw self::rechazo('contacto_telefono', 'Pediste varios códigos para este número. Inténtalo más tarde o continúa sin verificarlo.');
        }
        $enElDia = VerificacionWhatsApp::query()->where('created_at', '>=', now()->subDay())->count();
        if ($enElDia >= $this->parametros->entero('whatsapp.codigos_por_dia')) {
            $this->alertas->registrar('whatsapp_fallido', 'codigos:tope', 'Se alcanzó el tope diario de códigos de verificación por WhatsApp.');
            throw self::rechazo('contacto_telefono', 'Por ahora no podemos mandar códigos. Continúa sin verificarlo; podrás hacerlo después.');
        }

        $codigo = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        VerificacionWhatsApp::query()->create([
            'telefono' => $telefono,
            'codigo_hash' => self::hash($codigo),
            'expira_en' => now()->addMinutes(self::MINUTOS_CODIGO),
            'ip' => $ip,
        ]);

        try {
            $this->whatsapp->enviarCodigo($telefono, PlantillasWhatsApp::CODIGO_VERIFICACION, $codigo);
        } catch (RuntimeException $e) {
            // Token vencido o plantilla sin aprobar: el superadmin lo sabe.
            $this->alertas->registrar('whatsapp_fallido', 'codigos:envio', 'No salió un código de verificación: '.$e->getMessage());
            throw self::rechazo('contacto_telefono', 'No pudimos mandar el código a ese WhatsApp. Revisa el número o continúa sin verificarlo.');
        }
    }

    /**
     * Confirma el código del último envío a ese número y devuelve el comprobante.
     */
    public function verificar(string $telefono, #[\SensitiveParameter] string $codigo): string
    {
        // El rechazo se lanza fuera de la transacción: un intento fallido cuenta.
        $resultado = DB::transaction(function () use ($telefono, $codigo): string|ValidationException {
            $verificacion = VerificacionWhatsApp::query()
                ->where('telefono', $telefono)
                ->whereNull('verificada_en')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $verificacion instanceof VerificacionWhatsApp || $verificacion->expira_en->isPast()) {
                return self::rechazo('codigo', 'El código venció. Pide uno nuevo.');
            }
            if ($verificacion->intentos >= self::MAX_INTENTOS) {
                return self::rechazo('codigo', 'Demasiados intentos. Pide un código nuevo.');
            }
            if (! hash_equals($verificacion->codigo_hash, self::hash($codigo))) {
                $verificacion->increment('intentos');

                return self::rechazo('codigo', 'El código no es correcto.');
            }

            $comprobante = Str::random(48);
            $verificacion->forceFill([
                'verificada_en' => now(),
                'comprobante_hash' => hash('sha256', $comprobante),
            ])->save();

            return $comprobante;
        });

        if ($resultado instanceof ValidationException) {
            throw $resultado;
        }

        return $resultado;
    }

    /**
     * El comprobante es de ese número, sigue vigente y no se ha usado.
     */
    public function comprobanteValido(string $telefono, string $comprobante): ?VerificacionWhatsApp
    {
        $verificacion = VerificacionWhatsApp::query()
            ->where('comprobante_hash', hash('sha256', $comprobante))
            ->where('telefono', $telefono)
            ->whereNull('usada_en')
            ->first();

        return $verificacion instanceof VerificacionWhatsApp
            && $verificacion->verificada_en?->gt(now()->subMinutes(self::MINUTOS_COMPROBANTE))
            ? $verificacion
            : null;
    }

    public function usar(VerificacionWhatsApp $verificacion): void
    {
        $verificacion->forceFill(['usada_en' => now()])->save();
    }

    private static function hash(string $codigo): string
    {
        return hash_hmac('sha256', $codigo, (string) config('app.key'));
    }

    private static function rechazo(string $campo, string $mensaje): ValidationException
    {
        return ValidationException::withMessages([$campo => [$mensaje]]);
    }
}
