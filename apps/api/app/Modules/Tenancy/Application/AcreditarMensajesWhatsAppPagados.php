<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;

/**
 * Una compra de mensajes de WhatsApp pagada (cargo `whatsapp` del control plane) se
 * suma al saldo del negocio, en su base (ADR 0186). Una sola vez por cargo.
 */
class AcreditarMensajesWhatsAppPagados
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly MensajesWhatsAppTenant $mensajes,
    ) {}

    public function aplicar(?CargoRenta $cargo): void
    {
        if (! $cargo instanceof CargoRenta || $cargo->concepto !== 'whatsapp' || $cargo->estado !== EstadoCargoRenta::Pagado) {
            return;
        }
        $estudio = $cargo->estudio;
        if (! $estudio instanceof Estudio) {
            return;
        }

        $cantidad = (int) $cargo->alumnos_activos;
        $acreditar = fn () => $this->mensajes->acreditar($cantidad, 'cargo:'.$cargo->ulid, "Paquete de {$cantidad} mensajes de WhatsApp");
        $this->gestor->actual()?->is($estudio) === true ? $acreditar() : $this->gestor->ejecutarEn($estudio, $acreditar);
    }

    /**
     * Vuelve a acreditar lo pagado de un negocio, por si algo falló al pagarse: acreditar
     * es idempotente por el cargo (como los timbres; ADR 0231).
     */
    public function sincronizar(Estudio $estudio): void
    {
        CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('concepto', 'whatsapp')
            ->where('estado', EstadoCargoRenta::Pagado->value)
            ->get()
            ->each(fn (CargoRenta $cargo) => $this->aplicar($cargo->setRelation('estudio', $estudio)));
    }
}
