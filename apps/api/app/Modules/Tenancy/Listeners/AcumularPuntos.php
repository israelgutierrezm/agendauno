<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\PuntosTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Lealtad\OrigenPuntos;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProgramaLealtadTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;

/**
 * Acumula puntos de lealtad cuando ocurre un evento que los otorga: asistir a una clase
 * (`asistencia.marcada` con estado presente) o pagar una orden (`orden.pagada`). Sólo si
 * el programa está activo y el plan del negocio incluye lealtad (ADR 0107). Idempotente
 * por el ulid del evento (el relay es at-least-once).
 * Se registra sobre {@see EventoDeDominioTenant} en AppServiceProvider.
 */
class AcumularPuntos
{
    public function __construct(
        private readonly PuntosTenant $puntos,
        private readonly FuncionesPlan $funciones,
    ) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        // Cobro anulado (ADR 0087): se retiran los puntos de esa compra, aunque el
        // programa ya no esté activo (se dieron cuando lo estaba).
        if ($evento->tipo === 'pago.anulado') {
            $this->retirar($evento);

            return;
        }
        if (! in_array($evento->tipo, ['asistencia.marcada', 'orden.pagada'], true)) {
            return;
        }

        $programa = ProgramaLealtadTenant::query()->first();
        if (! $programa instanceof ProgramaLealtadTenant || ! $programa->activa || ! $this->funciones->tieneElNegocioActual('lealtad')) {
            return;
        }

        $payload = $evento->payload;
        $ulid = $payload['persona_id'] ?? null;
        $personaId = is_string($ulid) && $ulid !== ''
            ? (int) PersonaTenant::query()->where('ulid', $ulid)->value('id')
            : 0;
        if ($personaId <= 0) {
            return;
        }

        if ($evento->tipo === 'asistencia.marcada') {
            if (($payload['estado'] ?? null) !== 'presente') {
                return;
            }
            $this->puntos->acumularPorEvento(
                $personaId, $programa->puntos_por_asistencia, OrigenPuntos::Asistencia, $evento->eventoUlid,
                'Puntos por asistencia', 'asistencia', $this->comoTexto($payload['reserva_id'] ?? null),
            );

            return;
        }

        // orden.pagada: no si su cobro se anuló (el relay puede llegar tarde) ni si ya
        // dejó puntos vigentes (si se volvió a cobrar, un evento viejo no premia dos
        // veces).
        $ordenUlid = $this->comoTexto($payload['orden_id'] ?? null);
        if ($ordenUlid === null || $this->cobroAnulado($ordenUlid) || $this->puntos->vigentesDeCompra($ordenUlid) > 0) {
            return;
        }

        // Puntos por cada unidad de moneda del total (total en minor).
        $totalMinor = isset($payload['total_minor']) ? (int) $payload['total_minor'] : 0;
        $puntos = intdiv($totalMinor, 100) * $programa->puntos_por_moneda;
        $this->puntos->acumularPorEvento(
            $personaId, $puntos, OrigenPuntos::Compra, $evento->eventoUlid,
            'Puntos por compra', 'orden', $ordenUlid,
        );
    }

    private function retirar(EventoDeDominioTenant $evento): void
    {
        $ulid = $evento->payload['persona_id'] ?? null;
        $ordenUlid = $this->comoTexto($evento->payload['orden_id'] ?? null);
        $personaId = is_string($ulid) && $ulid !== ''
            ? (int) PersonaTenant::query()->where('ulid', $ulid)->value('id')
            : 0;
        if ($personaId <= 0 || $ordenUlid === null) {
            return;
        }
        $this->puntos->retirarDeCompra($personaId, $ordenUlid, $evento->eventoUlid);
    }

    /**
     * Anular deja la orden en pendiente (ADR 0087). Cancelada o reembolsada después,
     * el pago sí ocurrió. `value()` devuelve el enum ya casteado.
     */
    private function cobroAnulado(string $ordenUlid): bool
    {
        $estado = OrdenTenant::query()->where('ulid', $ordenUlid)->value('estado');

        return $estado === EstadoOrden::Pendiente || $estado === EstadoOrden::Pendiente->value;
    }

    private function comoTexto(mixed $valor): ?string
    {
        return is_string($valor) ? $valor : null;
    }
}
