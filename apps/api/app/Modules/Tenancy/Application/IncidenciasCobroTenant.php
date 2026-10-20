<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Bandeja "por conciliar": abre una incidencia por caso (sin duplicarla si ya está
 * abierta) y la cierra cuando se resuelve, sola o a mano.
 *
 * El superadmin se entera de cada incidencia nueva cuando ya quedó guardada (si la
 * transacción que la abría se deshace, no hay aviso de algo que no existe). Si su aviso
 * no sale, la incidencia queda sin `alertada_en` y {@see alertarPendientes()} lo
 * reintenta.
 */
class IncidenciasCobroTenant
{
    public function __construct(
        private readonly AlertasPlataforma $alertas,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /** Una incidencia nueva de dinero: el superadmin también se entera, ya guardada. */
    private function alertar(IncidenciaCobroTenant $incidencia): void
    {
        DB::connection('tenant')->afterCommit(function () use ($incidencia): void {
            $this->enviarAlerta($incidencia);
        });
    }

    private function enviarAlerta(IncidenciaCobroTenant $incidencia): bool
    {
        $slug = $this->gestor->actual()->slug ?? '';
        if (! $this->alertas->registrar('incidencia_cobro', "{$slug}:{$incidencia->tipo}", "{$incidencia->tipo}: {$incidencia->detalle}")) {
            return false;
        }
        $incidencia->update(['alertada_en' => now()]);

        return true;
    }

    /**
     * Reintenta el aviso al superadmin de las incidencias abiertas cuyo aviso no salió
     * (lo corre `agendauno:conciliar-pagos`). Cuántos salieron.
     */
    public function alertarPendientes(): int
    {
        return IncidenciaCobroTenant::query()
            ->where('estado', IncidenciaCobroTenant::ABIERTA)
            ->whereNull('alertada_en')
            // Una recién abierta aún puede estar avisando por su cuenta.
            ->where('created_at', '<=', now()->subMinute())
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->filter(fn (IncidenciaCobroTenant $incidencia): bool => $this->enviarAlerta($incidencia))
            ->count();
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

        $incidencia = IncidenciaCobroTenant::query()->create([
            'tipo' => $tipo,
            'estado' => IncidenciaCobroTenant::ABIERTA,
            'pago_id' => $reembolso->pago_id,
            'reembolso_id' => $reembolso->getKey(),
            'detalle' => $detalle,
            'datos' => $datos,
        ]);
        $this->alertar($incidencia);

        return $incidencia;
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
            $this->alertar($incidencia);
        }

        return $incidencia;
    }

    /**
     * Abre la incidencia de un pago automático (ADR 0134): una cancelación que la
     * pasarela no confirma, o un cobro suyo que no se esperaba o con otro importe. Con
     * la referencia del cobro, una sola por cobro (aunque ya se haya resuelto); sin
     * ella, una abierta a la vez.
     *
     * @param  array<string, mixed>  $datos
     */
    public function porDomiciliacion(
        string $tipo,
        DomiciliacionTenant $domiciliacion,
        string $detalle,
        array $datos = [],
        ?PagoTenant $pago = null,
        ?string $referencia = null,
    ): IncidenciaCobroTenant {
        $existente = IncidenciaCobroTenant::query()
            ->where('tipo', $tipo)
            ->where('domiciliacion_id', $domiciliacion->getKey())
            ->when(
                $referencia !== null,
                fn ($q) => $q->where('referencia_externa', $referencia),
                fn ($q) => $q->where('estado', IncidenciaCobroTenant::ABIERTA),
            )
            ->first();
        if ($existente instanceof IncidenciaCobroTenant) {
            return $existente;
        }

        $incidencia = IncidenciaCobroTenant::query()->create([
            'tipo' => $tipo,
            'estado' => IncidenciaCobroTenant::ABIERTA,
            'pago_id' => $pago?->getKey(),
            'orden_id' => $pago?->orden_id,
            'domiciliacion_id' => $domiciliacion->getKey(),
            'referencia_externa' => $referencia,
            'detalle' => $detalle,
            'datos' => $datos,
        ]);
        $this->alertar($incidencia);

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
