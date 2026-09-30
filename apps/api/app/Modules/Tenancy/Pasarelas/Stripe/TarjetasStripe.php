<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\Stripe;

use App\Modules\Tenancy\Application\DomiciliacionesTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ClientePasarelaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTarjetaTenant;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\Pasarelas\TarjetaGuardada;

/**
 * Tarjetas autorizadas en Stripe Checkout para pagos automáticos. Al terminar una
 * sesión que guardó tarjeta (modo `setup` desde la cuenta del alumno, o una compra
 * con `domiciliar`), la tarjeta pasa a cobrar sus membresías domiciliadas.
 *
 * La persona se identifica por su cliente de Stripe (no por la metadata); solo se
 * domicilian membresías suyas. Si el aviso no llega, la conciliación hace lo mismo
 * ({@see ConciliarTarjetasStripe}, ADR 0076).
 */
class TarjetasStripe
{
    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly DomiciliacionesTenant $domiciliaciones,
    ) {}

    /**
     * @param  array<string, mixed>  $sesion  el objeto `checkout.session` del webhook
     */
    public function sesionCompletada(array $sesion): void
    {
        $sesionId = (string) ($sesion['id'] ?? '');
        $modo = (string) ($sesion['mode'] ?? '');
        $orden = $modo === 'payment' ? $this->ordenDomiciliada($sesionId) : null;
        if ($sesionId === '' || ($modo !== 'setup' && ! $orden instanceof OrdenTenant)) {
            return; // un pago normal: no guardó tarjeta
        }

        $secretKey = $this->registro->llaves('stripe')['secret_key'] ?? '';
        if ($secretKey === '') {
            return;
        }
        $datos = (new ClienteStripe($secretKey))->tarjetaDeSesion($sesionId);
        // Ya se sabe cómo terminó: la conciliación no vuelve a preguntar.
        SesionTarjetaTenant::query()
            ->where('referencia', $sesionId)
            ->where('estado', SesionTarjetaTenant::PENDIENTE)
            ->update(['estado' => SesionTarjetaTenant::COMPLETADA, 'revisada_en' => now()]);
        if ($datos === null) {
            return;
        }

        $personaId = ClientePasarelaTenant::query()
            ->where('proveedor', 'stripe')
            ->where('cliente_externo', $datos['cliente'])
            ->value('persona_id');
        $persona = $personaId !== null ? PersonaTenant::query()->find($personaId) : null;
        if (! $persona instanceof PersonaTenant) {
            return;
        }

        $this->domiciliaciones->registrarTarjeta(
            $persona,
            'stripe',
            new TarjetaGuardada($datos['metodo'], $datos['marca'], $datos['ultimos4'], $datos['expira_mes'], $datos['expira_anio']),
            [...$this->acuerdosDeOrden($orden), ...$this->acuerdosElegidos($datos['metadata'])],
        );
    }

    /**
     * La orden (con pago automático) que se pagó con esta sesión, si la hay.
     */
    private function ordenDomiciliada(string $sesionId): ?OrdenTenant
    {
        $pago = PagoTenant::query()->where('referencia_externa', $sesionId)->with('orden')->first();
        $orden = $pago?->orden;

        return $orden instanceof OrdenTenant && $orden->domiciliar ? $orden : null;
    }

    /**
     * Las membresías que dejó la compra: la que se renovó o las que concedió.
     *
     * @return list<int>
     */
    private function acuerdosDeOrden(?OrdenTenant $orden): array
    {
        if (! $orden instanceof OrdenTenant) {
            return [];
        }
        if ($orden->renueva_acuerdo_id !== null) {
            return [(int) $orden->renueva_acuerdo_id];
        }

        return AcuerdoTenant::query()
            ->whereIn('linea_orden_id', $orden->lineas()->pluck('id'))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Las membresías que el alumno eligió domiciliar desde su cuenta (metadata).
     *
     * @param  array<string, mixed>  $metadata
     * @return list<int>
     */
    private function acuerdosElegidos(array $metadata): array
    {
        $ulids = array_values(array_filter(explode(',', (string) ($metadata['acuerdos'] ?? ''))));
        if ($ulids === []) {
            return [];
        }

        return AcuerdoTenant::query()
            ->whereIn('ulid', $ulids)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
