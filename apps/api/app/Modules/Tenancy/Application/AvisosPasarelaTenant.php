<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AvisoPasarelaTenant;
use App\Modules\Tenancy\Pasarelas\MercadoPago\NotificacionesMercadoPago;
use App\Modules\Tenancy\Pasarelas\OpenPay\NotificacionesOpenPay;
use App\Modules\Tenancy\Pasarelas\Stripe\NotificacionesStripe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * La bandeja de los avisos de las pasarelas de cobro (ADR 0173). El webhook comprueba
 * la firma, guarda el aviso y contesta al momento; procesarlo (confirmar el pago y lo
 * que compró, releer el cobro en Mercado Pago u OpenPay) va después de responder y, si
 * algo falla, `agendauno:procesar-avisos-pasarela` lo reintenta cada vez más espaciado
 * (2, 4, 8 y 16 minutos). Al quinto intento queda «fallido» y el superadmin se entera;
 * si era un cobro, la conciliación de pagos lo retoma.
 */
class AvisosPasarelaTenant
{
    /** Unos 26 h de reintentos (ADR 0231): una caída de horas no pierde un aviso. */
    public const MAX_INTENTOS = 30;

    /** Espera doble en cada intento, hasta este tope. */
    private const MINUTOS_MAXIMOS_ENTRE_INTENTOS = 60;

    /** Lo recién llegado lo procesa su propia petición: el comando lo deja un minuto. */
    private const SEGUNDOS_DE_GRACIA = 60;

    public function __construct(
        private readonly NotificacionesStripe $stripe,
        private readonly NotificacionesMercadoPago $mercadoPago,
        private readonly NotificacionesOpenPay $openPay,
        private readonly AlertasPlataforma $alertas,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /**
     * Guarda el aviso. Con `clave` (el id que da la pasarela), el mismo aviso no se
     * guarda dos veces.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recibir(string $proveedor, ?string $tipo, array $payload, ?string $clave = null): AvisoPasarelaTenant
    {
        $clave = $clave !== null && $clave !== '' ? Str::limit($clave, 120, '') : null;
        if ($clave !== null) {
            $existente = AvisoPasarelaTenant::query()->where('proveedor', $proveedor)->where('clave', $clave)->first();
            if ($existente instanceof AvisoPasarelaTenant) {
                return $existente;
            }
        }
        try {
            return AvisoPasarelaTenant::query()->create([
                'proveedor' => $proveedor,
                'tipo' => $tipo !== null && $tipo !== '' ? Str::limit($tipo, 80, '') : null,
                'clave' => $clave,
                'payload' => $payload,
                'estado' => AvisoPasarelaTenant::PENDIENTE,
                'recibido_en' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // La pasarela reintentó mientras se guardaba el primero.
            return AvisoPasarelaTenant::query()->where('proveedor', $proveedor)->where('clave', $clave)->firstOrFail();
        }
    }

    /** Vuelve a poner en cola los que agotaron sus intentos (al volver la pasarela); cuántos. */
    public function reintentarFallidos(): int
    {
        return AvisoPasarelaTenant::query()
            ->where('estado', AvisoPasarelaTenant::FALLIDO)
            ->update(['estado' => AvisoPasarelaTenant::PENDIENTE, 'intentos' => 0, 'reintentar_en' => now(), 'procesado_en' => null]);
    }

    /** Procesa los pendientes del negocio conectado que ya toca; cuántos terminó. */
    public function procesarPendientes(): int
    {
        $terminados = 0;
        AvisoPasarelaTenant::query()
            ->where('estado', AvisoPasarelaTenant::PENDIENTE)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $nuevo) => $nuevo->whereNull('reintentar_en')->where('recibido_en', '<=', now()->subSeconds(self::SEGUNDOS_DE_GRACIA)))
                ->orWhere('reintentar_en', '<=', now()))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (AvisoPasarelaTenant $aviso) use (&$terminados): void {
                $this->procesar($aviso);
                if ($aviso->estado !== AvisoPasarelaTenant::PENDIENTE) {
                    $terminados++;
                }
            });

        return $terminados;
    }

    public function procesar(AvisoPasarelaTenant $aviso): void
    {
        if ($aviso->estado !== AvisoPasarelaTenant::PENDIENTE) {
            return;
        }

        try {
            match ($aviso->proveedor) {
                'stripe' => $this->stripe->procesar($aviso->payload),
                'mercadopago' => $this->mercadoPago->procesar((string) ($aviso->payload['tipo'] ?? ''), (string) ($aviso->payload['id'] ?? '')),
                'openpay' => $this->openPay->procesar($aviso->payload),
                default => throw new RuntimeException("No hay quién procese los avisos de {$aviso->proveedor}."),
            };
            $aviso->terminar(AvisoPasarelaTenant::PROCESADO);
        } catch (Throwable $e) {
            report($e);
            $aviso->intentos++;
            $aviso->resultado = Str::limit($e->getMessage(), 290);
            if ($aviso->intentos >= self::MAX_INTENTOS) {
                $aviso->estado = AvisoPasarelaTenant::FALLIDO;
                $aviso->reintentar_en = null;
                $aviso->procesado_en = now();
                $this->alertar($aviso);
            } else {
                $aviso->reintentar_en = now()->addMinutes(min(2 ** min($aviso->intentos, 6), self::MINUTOS_MAXIMOS_ENTRE_INTENTOS));
            }
            $aviso->save();
        }
    }

    private function alertar(AvisoPasarelaTenant $aviso): void
    {
        $slug = (string) ($this->gestor->actual()->slug ?? '');
        $pasarela = match ($aviso->proveedor) {
            'stripe' => 'Stripe',
            'mercadopago' => 'Mercado Pago',
            'openpay' => 'OpenPay',
            default => $aviso->proveedor,
        };
        $this->alertas->registrar(
            'aviso_pasarela_fallido',
            "{$slug}:{$aviso->proveedor}",
            "Un aviso de {$pasarela}".($aviso->tipo !== null ? " ({$aviso->tipo})" : '')
                ." no se pudo procesar tras {$aviso->intentos} intentos: {$aviso->resultado} Si era un cobro, la conciliación de pagos lo retoma.",
        );
    }
}
