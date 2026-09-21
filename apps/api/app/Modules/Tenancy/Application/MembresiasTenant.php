<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Creditos\OrigenMovimiento;
use App\Modules\Creditos\TipoMovimiento;
use App\Modules\Membresias\PoliticaReset;
use App\Modules\Membresias\PoliticaRollover;
use App\Modules\Membresias\TipoProducto;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Membresias tenant-local: crea productos, los vende (acuerdo → derecho + concesion
 * de creditos) y agrega top-ups. El derecho copia la plantilla de
 * ciclo/rollover/restricciones del producto. Todo sobre la BD del tenant resuelto.
 */
class MembresiasTenant
{
    public function __construct(private readonly LibroMayorTenant $libro) {}

    public function crearProducto(
        string $nombre,
        TipoProducto $tipo,
        int $precioMinor,
        string $moneda,
        bool $ilimitado,
        ?int $creditosIncluidos,
        PoliticaReset $politicaReset = PoliticaReset::Ninguno,
        ?int $unidadesPorCiclo = null,
        PoliticaRollover $politicaRollover = PoliticaRollover::Ninguno,
        ?int $rolloverMax = null,
        ?int $actividadId = null,
        ?int $sucursalId = null,
        ?int $vigenciaDias = null,
    ): ProductoTenant {
        return ProductoTenant::query()->create($this->normalizar([
            'nombre' => $nombre,
            'tipo' => $tipo,
            'precio_minor' => $precioMinor,
            'moneda' => $moneda,
            'ilimitado' => $ilimitado,
            'creditos_incluidos' => $creditosIncluidos,
            'vigencia_dias' => $vigenciaDias,
            'archivado' => false,
            'politica_reset' => $politicaReset,
            'unidades_por_ciclo' => $unidadesPorCiclo,
            'politica_rollover' => $politicaRollover,
            'rollover_max' => $rolloverMax,
            'actividad_id' => $actividadId,
            'sucursal_id' => $sucursalId,
        ]));
    }

    /**
     * Edita un producto (editor completo). Recibe los atributos ya resueltos (enums,
     * ids internos) y aplica las mismas reglas de coherencia que el alta. No toca los
     * acuerdos/derechos ya vendidos: solo cambia la plantilla para ventas futuras.
     *
     * @param  array<string, mixed>  $atributos
     */
    public function actualizarProducto(ProductoTenant $producto, array $atributos): ProductoTenant
    {
        // Base = estado actual del producto; encima, los campos provistos.
        $fusion = array_merge([
            'ilimitado' => $producto->ilimitado,
            'creditos_incluidos' => $producto->creditos_incluidos,
            'vigencia_dias' => $producto->vigencia_dias,
            'archivado' => $producto->archivado,
            'politica_reset' => $producto->politica_reset,
            'unidades_por_ciclo' => $producto->unidades_por_ciclo,
            'politica_rollover' => $producto->politica_rollover,
            'rollover_max' => $producto->rollover_max,
        ], $atributos);

        $producto->update($this->normalizar($fusion));

        return $producto->refresh();
    }

    /**
     * Reglas de coherencia de un producto: ilimitado no lleva créditos ni cupo por
     * ciclo; sin recurrencia no hay cupo por ciclo; rollover_max solo si es limitado.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function normalizar(array $a): array
    {
        $ilimitado = (bool) ($a['ilimitado'] ?? false);
        $politicaReset = $a['politica_reset'] ?? PoliticaReset::Ninguno;
        $politicaRollover = $a['politica_rollover'] ?? PoliticaRollover::Ninguno;
        $recurrente = $politicaReset !== PoliticaReset::Ninguno;

        $a['creditos_incluidos'] = $ilimitado ? null : ($a['creditos_incluidos'] ?? null);
        $a['unidades_por_ciclo'] = ($ilimitado || ! $recurrente) ? null : ($a['unidades_por_ciclo'] ?? null);
        $a['rollover_max'] = $politicaRollover === PoliticaRollover::Limitado ? ($a['rollover_max'] ?? null) : null;

        return $a;
    }

    /**
     * Vende un producto a una persona: crea el acuerdo, otorga el derecho (copiando
     * la plantilla del producto) y concede sus creditos en el ledger, todo en una
     * sola transaccion. Producto recurrente: inicializa el primer ciclo y concede su
     * cupo; pack: concede sus creditos incluidos.
     */
    public function venderProducto(PersonaTenant $persona, ProductoTenant $producto, ?string $fechaInicio = null, ?Usuario $actor = null): AcuerdoTenant
    {
        return DB::connection('tenant')->transaction(function () use ($persona, $producto, $fechaInicio, $actor): AcuerdoTenant {
            $inicio = $fechaInicio ?? Carbon::now()->toDateString();

            $acuerdo = AcuerdoTenant::query()->create([
                'persona_id' => $persona->getKey(),
                'producto_comercial_id' => $producto->getKey(),
                'fecha_inicio' => $inicio,
                'estado' => 'activo',
            ]);

            $politicaReset = $producto->politica_reset ?? PoliticaReset::Ninguno;
            $politicaRollover = $producto->politica_rollover ?? PoliticaRollover::Ninguno;

            $recurrente = $politicaReset !== PoliticaReset::Ninguno;
            [$cicloInicio, $cicloFin] = $recurrente
                ? $this->ventanaCiclo($politicaReset, $inicio)
                : [null, null];

            // Vigencia del producto → ventana de validez del derecho (feed del radar de
            // retención y del control de acceso). Sin vigencia, no expira por fecha.
            $validoHasta = $producto->vigencia_dias !== null
                ? Carbon::parse($inicio)->addDays($producto->vigencia_dias)->toDateString()
                : null;

            $derecho = $acuerdo->derechos()->create([
                'ambito' => 'general',
                'actividad_id' => $producto->actividad_id,
                'sucursal_id' => $producto->sucursal_id,
                'ilimitado' => $producto->ilimitado,
                'politica_reset' => $politicaReset->value,
                'unidades_por_ciclo' => $producto->unidades_por_ciclo,
                'politica_rollover' => $politicaRollover->value,
                'rollover_max' => $producto->rollover_max,
                'ciclo_inicio' => $cicloInicio,
                'ciclo_fin' => $cicloFin,
                'valido_desde' => $inicio,
                'valido_hasta' => $validoHasta,
            ]);

            if (! $producto->ilimitado) {
                $concesion = $recurrente
                    ? ($producto->unidades_por_ciclo ?? 0)
                    : ($producto->creditos_incluidos ?? 0);

                if ($concesion > 0) {
                    $this->libro->registrar(
                        $derecho,
                        TipoMovimiento::Concesion,
                        $concesion,
                        $recurrente ? 'Concesion de ciclo' : 'Concesion inicial',
                        ContextoMovimiento::para(OrigenMovimiento::Venta, 'acuerdo', $acuerdo->ulid, $actor),
                    );
                }
            }

            return $acuerdo;
        });
    }

    /**
     * Agrega un add-on / top-up a un derecho: un asiento adicional en el ledger, sin
     * editar la membresia original. Bloquea el derecho para que la instantánea de
     * `saldo_posterior` sea consistente ante top-ups concurrentes, y deja trazable
     * quién concedió el crédito.
     */
    public function agregarTopUp(DerechoTenant $derecho, int $unidades, ?string $descripcion = null, ?Usuario $actor = null): MovimientoCreditoTenant
    {
        return DB::connection('tenant')->transaction(function () use ($derecho, $unidades, $descripcion, $actor): MovimientoCreditoTenant {
            $bloqueado = DerechoTenant::query()->whereKey($derecho->getKey())->lockForUpdate()->firstOrFail();

            return $this->libro->registrar(
                $bloqueado,
                TipoMovimiento::AddOn,
                $unidades,
                $descripcion ?? 'Add-on / top-up',
                ContextoMovimiento::para(OrigenMovimiento::TopUp, null, null, $actor),
            );
        });
    }

    /**
     * Ventana del primer ciclo segun la politica de reset.
     *
     * @return array{0: string, 1: string}
     */
    private function ventanaCiclo(PoliticaReset $politica, string $fecha): array
    {
        $dia = Carbon::parse($fecha);

        if ($politica === PoliticaReset::Calendario) {
            return [
                $dia->copy()->startOfMonth()->toDateString(),
                $dia->copy()->endOfMonth()->toDateString(),
            ];
        }

        // Aniversario: desde la fecha, un mes menos un dia.
        return [
            $dia->toDateString(),
            $dia->copy()->addMonth()->subDay()->toDateString(),
        ];
    }
}
