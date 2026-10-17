<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoTicketVenta;
use App\Modules\Tenancy\Models\LineaVentaPosTenant;
use App\Modules\Tenancy\Models\VentaPosTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * El ticket de una venta del mostrador (ADR 0145): lo que ve quien compró en «Mis
 * compras» y lo que se le manda por correo si quien cobra lo marca. A público en
 * general no se le manda.
 */
class TicketVentaPosTenant
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * Folio corto para ubicar la venta (el final de su ulid; no expone el consecutivo).
     */
    public static function folio(VentaPosTenant $venta): string
    {
        return strtoupper(substr((string) $venta->ulid, -8));
    }

    /**
     * Si se cobró al terminar una cita, el servicio va primero (`servicio`).
     *
     * @return array{id: string, folio: string, creado_en: string|null, sucursal: string|null, servicio: array{nombre: string|null, monto_minor: int}|null, lineas: list<array{articulo: string|null, cantidad: int, extras: list<string>, subtotal_minor: int}>, total_minor: int, propina_minor: int, cobrado_minor: int, recibido_minor: int|null, cambio_minor: int|null, moneda: string, metodo_pago: string, anulada: bool}
     */
    public function datos(VentaPosTenant $venta): array
    {
        $venta->loadMissing(['sucursal', 'lineas.articulo', 'orden.sesion.oferta']);

        return [
            'id' => (string) $venta->ulid,
            'folio' => self::folio($venta),
            'creado_en' => $venta->created_at?->toIso8601String(),
            'sucursal' => $venta->sucursal?->nombre,
            'servicio' => $venta->orden_id !== null && $venta->servicio_minor > 0
                ? ['nombre' => $venta->orden?->sesion?->oferta?->nombre, 'monto_minor' => $venta->servicio_minor]
                : null,
            'lineas' => array_values($venta->lineas->map(static fn (LineaVentaPosTenant $l): array => [
                'articulo' => $l->articulo?->nombre,
                'cantidad' => (int) $l->cantidad,
                'extras' => array_values(array_map(static fn (array $e): string => (string) $e['nombre'], $l->extras ?? [])),
                'subtotal_minor' => (int) $l->subtotal_minor,
            ])->all()),
            'total_minor' => $venta->total_minor,
            'propina_minor' => $venta->propina_minor,
            'cobrado_minor' => $venta->cobradoMinor(),
            'recibido_minor' => $venta->recibido_minor,
            'cambio_minor' => $venta->recibido_minor !== null ? $venta->recibido_minor - $venta->cobradoMinor() : null,
            'moneda' => (string) $venta->moneda,
            'metodo_pago' => (string) $venta->metodo_pago,
            'anulada' => $venta->anulada_en !== null,
        ];
    }

    /**
     * Se lo manda a quien compró, si tiene correo. Si el correo no sale, la venta queda
     * registrada igual (se reporta el error y no se marca como enviado).
     */
    public function enviar(VentaPosTenant $venta): bool
    {
        $persona = $venta->persona;
        $estudio = $this->gestor->actual();
        if ($persona === null || ($persona->email ?? '') === '' || $estudio === null) {
            return false;
        }

        $ticket = $this->datos($venta);
        $creado = $venta->created_at !== null ? CarbonImmutable::instance($venta->created_at) : CarbonImmutable::now();
        $fecha = $creado->setTimezone($this->fechas->zona())->locale('es')->isoFormat('D [de] MMMM [de] YYYY, H:mm');

        try {
            Mail::to((string) $persona->email)->send(new CorreoTicketVenta(
                (string) $estudio->slug,
                (string) $estudio->nombre,
                $ticket + ['fecha' => $fecha, 'nombre' => (string) $persona->nombre],
                $estudio->contacto_email,
            ));
        } catch (Throwable $e) {
            report($e);

            return false;
        }
        $venta->update(['ticket_enviado_en' => now()]);

        return true;
    }
}
