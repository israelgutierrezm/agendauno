<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

/**
 * Un negocio de citas deja de tener membresías (ADR 0130), en su base conectada:
 * - sus membresías (y los planes que se renuevan, ilimitados, con permanencia o con
 *   cuotas) se archivan: ya no se venden;
 * - las que ya tiene alguien terminan en su fecha: al acabar el periodo pagado, sin
 *   renovarse ni cobrarse solas. Es la misma baja que da el negocio
 *   ({@see BajaDeMembresiaTenant}), sin penalización ni adeudo: se anula la renovación
 *   por cobrar, se quita el pago automático y sus planes valen hasta esa fecha.
 *
 * Lo usan el comando `agendauno:retirar-membresias-citas`, el cambio de modalidad a
 * citas y el cobro de renovaciones (si encuentra una en un negocio de citas). Volver a
 * correrlo no cambia nada.
 */
class RetirarMembresiasCitasTenant
{
    public const MOTIVO = 'Los negocios de citas venden bonos de sesiones, no membresías.';

    public function __construct(
        private readonly BajaDeMembresiaTenant $bajas,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * Lo que hay por retirar, sin cambiar nada.
     *
     * @return array{productos: int, renovables: int}
     */
    public function revisar(): array
    {
        return ['productos' => $this->productos()->count(), 'renovables' => $this->renovables()->count()];
    }

    /**
     * @return array{productos: int, renovables: int} lo que se retiró
     */
    public function retirar(?Usuario $actor = null): array
    {
        $productos = 0;
        foreach ($this->productos()->get() as $producto) {
            $producto->update(['archivado' => true]);
            $this->auditoria->registrar($actor, 'producto.actualizado', 'producto', (string) $producto->ulid,
                ['archivado' => false], ['archivado' => true], self::MOTIVO);
            $productos++;
        }

        $renovables = 0;
        foreach ($this->renovables()->get() as $acuerdo) {
            $this->terminarEnSuFecha($acuerdo, $actor);
            $renovables++;
        }

        return ['productos' => $productos, 'renovables' => $renovables];
    }

    /** Una membresía que se renueva termina al acabar su periodo pagado. */
    public function terminarEnSuFecha(AcuerdoTenant $acuerdo, ?Usuario $actor = null): void
    {
        $this->bajas->ejecutar($acuerdo, self::MOTIVO, exentarPenalizacion: true, conservarAdeudo: false, actor: $actor);
    }

    /**
     * @return Builder<ProductoTenant>
     */
    private function productos(): Builder
    {
        return MembresiasPorModalidadTenant::soloMembresias(ProductoTenant::query()->where('archivado', false));
    }

    /**
     * Las que se renuevan y aún no tienen baja.
     *
     * @return Builder<AcuerdoTenant>
     */
    private function renovables(): Builder
    {
        return AcuerdoTenant::query()
            ->whereNotNull('dia_ancla')
            ->whereNotNull('proxima_cobro_en')
            ->whereNull('baja_efectiva_el')
            ->where('estado', '!=', EstadoAcuerdo::Cancelado->value);
    }
}
