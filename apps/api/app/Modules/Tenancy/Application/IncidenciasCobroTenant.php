<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Bandeja "por conciliar": abre una incidencia por caso (sin duplicarla si ya está
 * abierta) y la cierra cuando se resuelve, sola o a mano.
 */
class IncidenciasCobroTenant
{
    public function __construct(
        private readonly AlertasPlataforma $alertas,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /** Una incidencia nueva de dinero: el superadmin también se entera. */
    private function alertar(string $tipo, string $detalle): void
    {
        $slug = $this->gestor->actual()->slug ?? '';
        $this->alertas->registrar('incidencia_cobro', "{$slug}:{$tipo}", "{$tipo}: {$detalle}");
    }

    /**
     * Abre (o deja abierta) la incidencia de una devolución.
     *
     * @param  array<string, mixed>  $datos
     */
    public function porReembolso(string $tipo, ReembolsoTenant $reembolso, string $detalle, array $datos = []): IncidenciaCobroTenant
    {
        $abierta = IncidenciaCobroTenant::query()
            ->where('tipo', $tipo)
            ->where('reembolso_id', $reembolso->getKey())
            ->where('estado', IncidenciaCobroTenant::ABIERTA)
            ->first();
        if ($abierta instanceof IncidenciaCobroTenant) {
            $abierta->update(['detalle' => $detalle, 'datos' => $datos]);

            return $abierta;
        }

        $this->alertar($tipo, $detalle);

        return IncidenciaCobroTenant::query()->create([
            'tipo' => $tipo,
            'estado' => IncidenciaCobroTenant::ABIERTA,
            'pago_id' => $reembolso->pago_id,
            'reembolso_id' => $reembolso->getKey(),
            'detalle' => $detalle,
            'datos' => $datos,
        ]);
    }

    /**
     * Abre (o deja abierta) la incidencia de un pago (p. ej. llegó tarde o dos veces).
     *
     * @param  array<string, mixed>  $datos
     */
    public function porPago(string $tipo, PagoTenant $pago, string $detalle, array $datos = []): IncidenciaCobroTenant
    {
        $incidencia = IncidenciaCobroTenant::query()->firstOrCreate(
            ['tipo' => $tipo, 'pago_id' => $pago->getKey(), 'estado' => IncidenciaCobroTenant::ABIERTA],
            ['orden_id' => $pago->orden_id, 'detalle' => $detalle, 'datos' => $datos],
        );
        if ($incidencia->wasRecentlyCreated) {
            $this->alertar($tipo, $detalle);
        }

        return $incidencia;
    }

    /**
     * Cierra las incidencias abiertas de una devolución que ya quedó resuelta.
     */
    public function cerrarDeReembolso(ReembolsoTenant $reembolso, string $resolucion, ?Usuario $actor = null): void
    {
        IncidenciaCobroTenant::query()
            ->where('reembolso_id', $reembolso->getKey())
            ->where('estado', IncidenciaCobroTenant::ABIERTA)
            ->get()
            ->each(fn (IncidenciaCobroTenant $incidencia) => $this->cerrar($incidencia, $resolucion, $actor));
    }

    public function cerrar(IncidenciaCobroTenant $incidencia, string $resolucion, ?Usuario $actor = null): void
    {
        $incidencia->update([
            'estado' => IncidenciaCobroTenant::RESUELTA,
            'resuelta_por' => $actor?->getKey(),
            'resuelta_en' => now(),
            'resolucion' => $resolucion,
        ]);
    }
}
