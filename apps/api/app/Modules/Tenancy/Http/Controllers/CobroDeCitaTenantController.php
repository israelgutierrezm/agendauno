<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\CobroDeCitaTenant;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\PuntoDeVentaTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\TicketVentaPosTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cobrar al terminar una cita: servicio, productos y propina en un solo cobro, con su
 * comprobante (la regla vive en {@see CobroDeCitaTenant}). El envío del ticket va
 * aparte del cobro (R06): si el correo falla, el cobro queda y el ticket se reenvía
 * con `POST /pos/ventas/{venta}/ticket`.
 */
class CobroDeCitaTenantController
{
    private const METODOS = ['efectivo', 'transferencia', 'tarjeta', 'ventanilla', 'manual'];

    public function __construct(
        private readonly CobroDeCitaTenant $cobro,
        private readonly PuntoDeVentaTenant $pos,
        private readonly TicketVentaPosTenant $tickets,
    ) {}

    public function cobrar(Request $request): JsonResponse
    {
        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->firstOrFail();
        $validado = $request->validate([
            'metodo' => ['required', Rule::in(self::METODOS)],
            'referencia' => ['nullable', 'string', 'max:255'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.articulo_id' => ['required', 'string'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.extras' => ['nullable', 'array', 'max:30'],
            'items.*.extras.*' => ['string'],
            'items.*.precio_minor' => ['nullable', 'integer', 'min:0'],
            'propina_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'recibido_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'enviar_ticket' => ['nullable', 'boolean'],
            'clave_intento' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ]);

        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;
        // Alcance: quien imparte, solo lo de sus clientes; el staff acotado, de sus sedes.
        $persona = $reserva->persona;
        app(AlcancePersonaTenant::class)->exigirPersona($request, $persona);
        $sucursalId = $reserva->sesion?->sucursal_id;
        abort_unless(
            $actor === null || app(ResolverAccesoTenant::class)->permiteSucursal($actor, $sucursalId !== null ? (int) $sucursalId : null),
            403,
            'No puedes cobrar en una sucursal que no te corresponde.',
        );

        // Vender productos pide el mostrador (su permiso y su función del plan).
        $pedidos = $validado['items'] ?? [];
        if ($pedidos !== []) {
            abort_unless($actor === null || $actor->puede('pos.vender'), 403, 'No tienes permiso para vender productos.');
            abort_unless(app(FuncionesPlan::class)->tieneElNegocioActual('inventario'), 403, 'Tu plan no incluye el mostrador.');
        }
        $enviarTicket = (bool) ($validado['enviar_ticket'] ?? false);
        if ($enviarTicket && ($persona->email ?? '') === '') {
            throw ValidationException::withMessages(['enviar_ticket' => ['No tiene correo: no se le puede mandar el ticket.']]);
        }

        $clave = $validado['clave_intento'] ?? null;
        $cobrar = fn (): array => $this->cobro->cobrar(
            $reserva,
            $validado['metodo'],
            $this->pos->lineasPedidas($pedidos),
            (int) ($validado['propina_minor'] ?? 0),
            isset($validado['recibido_minor']) ? (int) $validado['recibido_minor'] : null,
            $validado['referencia'] ?? null,
            $clave,
            $actor,
        );
        try {
            $resumen = $cobrar();
        } catch (UniqueConstraintViolationException $e) {
            // Dos intentos con la misma clave a la vez: el otro ya lo registró (lo mismo
            // lo recupera; otra operación con esa clave, 409).
            if ($clave === null || ! VentaPosTenant::query()->where('clave_intento', $clave)->exists()) {
                throw $e;
            }
            $resumen = $cobrar();
        }

        // El ticket, aparte del cobro: un reintento no lo vuelve a mandar si ya salió.
        $venta = $resumen['venta'];
        $envio = null;
        if ($enviarTicket && $venta !== null) {
            $envio = $venta->ticket_enviado_en !== null || $this->tickets->enviar($venta->load('persona')) ? 'enviado' : 'fallido';
        }

        return response()->json(['data' => [
            'servicio_minor' => $resumen['servicio_minor'],
            'productos_minor' => $resumen['productos_minor'],
            'propina_minor' => $resumen['propina_minor'],
            'total_minor' => $resumen['total_minor'],
            'recibido_minor' => $resumen['recibido_minor'],
            'cambio_minor' => $resumen['cambio_minor'],
            'moneda' => $resumen['moneda'],
            'metodo' => $resumen['metodo'],
            'orden_id' => $resumen['orden']?->ulid,
            // El comprobante de todo cobro (con el servicio) y cómo va su envío por correo:
            // `enviado`, `fallido` (se reenvía aparte) o null si no se pidió.
            'venta_id' => $venta?->ulid,
            'ticket' => $venta !== null ? $this->tickets->datos($venta) : null,
            'ticket_envio' => $envio,
        ]], 201);
    }
}
