<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Lealtad\OrigenPuntos;
use App\Modules\Tenancy\Application\PuntosTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProgramaLealtadTenant;

/**
 * Acumula puntos de lealtad cuando ocurre un evento que los otorga: asistir a una clase
 * (`asistencia.marcada` con estado presente) o pagar una orden (`orden.pagada`). Sólo si
 * el programa está activo. Idempotente por el ulid del evento (el relay es at-least-once).
 * Se registra sobre {@see EventoDeDominioTenant} en AppServiceProvider.
 */
class AcumularPuntos
{
    public function __construct(private readonly PuntosTenant $puntos) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        if (! in_array($evento->tipo, ['asistencia.marcada', 'orden.pagada'], true)) {
            return;
        }

        $programa = ProgramaLealtadTenant::query()->first();
        if (! $programa instanceof ProgramaLealtadTenant || ! $programa->activa) {
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

        // orden.pagada: puntos por cada unidad de moneda del total (total en minor).
        $totalMinor = isset($payload['total_minor']) ? (int) $payload['total_minor'] : 0;
        $puntos = intdiv($totalMinor, 100) * $programa->puntos_por_moneda;
        $this->puntos->acumularPorEvento(
            $personaId, $puntos, OrigenPuntos::Compra, $evento->eventoUlid,
            'Puntos por compra', 'orden', $this->comoTexto($payload['orden_id'] ?? null),
        );
    }

    private function comoTexto(mixed $valor): ?string
    {
        return is_string($valor) ? $valor : null;
    }
}
