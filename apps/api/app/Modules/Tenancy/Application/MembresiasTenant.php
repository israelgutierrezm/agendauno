<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\PoliticaRollover;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Membresias\VigenciaProducto;
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
    /** Una clase suelta es de una sola clase (1000 unidades = 1 crédito). */
    private const UNIDADES_CLASE_SUELTA = 1000;

    public function __construct(
        private readonly LibroMayorTenant $libro,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /**
     * @param  list<int>  $ofertaIds  clases a las que aplica (vacío = a todas)
     */
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
        ?VigenciaProducto $vigencia = null,
        array $ofertaIds = [],
    ): ProductoTenant {
        $producto = ProductoTenant::query()->create($this->normalizar([
            'nombre' => $nombre,
            'tipo' => $tipo,
            'precio_minor' => $precioMinor,
            'moneda' => $moneda,
            'ilimitado' => $ilimitado,
            'creditos_incluidos' => $creditosIncluidos,
            'vigencia_tipo' => $vigencia?->tipo,
            'vigencia_cantidad' => $vigencia?->cantidad,
            'archivado' => false,
            'politica_reset' => $politicaReset,
            'unidades_por_ciclo' => $unidadesPorCiclo,
            'politica_rollover' => $politicaRollover,
            'rollover_max' => $rolloverMax,
            'actividad_id' => $actividadId,
            'sucursal_id' => $sucursalId,
        ]));
        $producto->ofertas()->sync($ofertaIds);

        return $producto;
    }

    /**
     * Edita un producto (editor completo). Recibe los atributos ya resueltos (enums,
     * ids internos) y aplica las mismas reglas de coherencia que el alta. No toca los
     * acuerdos/derechos ya vendidos: solo cambia la plantilla para ventas futuras.
     * `$ofertaIds` (null = sin cambio) fija a qué clases aplica.
     *
     * @param  array<string, mixed>  $atributos
     * @param  list<int>|null  $ofertaIds
     */
    public function actualizarProducto(ProductoTenant $producto, array $atributos, ?array $ofertaIds = null): ProductoTenant
    {
        // Base = estado actual del producto; encima, los campos provistos.
        $fusion = array_merge([
            'tipo' => $producto->tipo,
            'ilimitado' => $producto->ilimitado,
            'creditos_incluidos' => $producto->creditos_incluidos,
            'vigencia_tipo' => $producto->vigencia_tipo,
            'vigencia_cantidad' => $producto->vigencia_cantidad,
            'archivado' => $producto->archivado,
            'politica_reset' => $producto->politica_reset,
            'unidades_por_ciclo' => $producto->unidades_por_ciclo,
            'politica_rollover' => $producto->politica_rollover,
            'rollover_max' => $producto->rollover_max,
        ], $atributos);

        $producto->update($this->normalizar($fusion));
        if ($ofertaIds !== null) {
            $producto->ofertas()->sync($ofertaIds);
        }

        return $producto->refresh();
    }

    /**
     * Reglas de coherencia de un producto: ilimitado no lleva créditos ni cupo por
     * ciclo; sin recurrencia no hay cupo por ciclo; rollover_max solo si es limitado;
     * una clase suelta es de una sola clase; sin tipo de vigencia no hay cantidad.
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

        if (($a['tipo'] ?? null) === TipoProducto::SesionIndividual) {
            $ilimitado = false;
            $a['ilimitado'] = false;
            $a['creditos_incluidos'] = self::UNIDADES_CLASE_SUELTA;
        }
        if (($a['vigencia_tipo'] ?? null) === null) {
            $a['vigencia_cantidad'] = null;
        }

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
            // La fecha de compra es la del negocio (no la del servidor en UTC).
            $inicio = $fechaInicio ?? Carbon::now($this->zona())->toDateString();

            $politicaReset = $producto->politica_reset ?? PoliticaReset::Ninguno;
            $politicaRollover = $producto->politica_rollover ?? PoliticaRollover::Ninguno;

            $recurrente = $politicaReset !== PoliticaReset::Ninguno;
            [$cicloInicio, $cicloFin] = $recurrente
                ? $this->ventanaCiclo($politicaReset, $inicio)
                : [null, null];

            $acuerdo = AcuerdoTenant::query()->create([
                'persona_id' => $persona->getKey(),
                'producto_comercial_id' => $producto->getKey(),
                'fecha_inicio' => $inicio,
                // Recurrente: el próximo cobro vence al cerrar el primer ciclo (el
                // scheduler lo cobrará). No recurrente (pack/pase): no se renueva.
                'proxima_cobro_en' => $recurrente && $cicloFin !== null ? Carbon::parse($cicloFin)->addDay()->toDateString() : null,
                'estado' => 'activo',
            ]);

            // Vigencia del producto → ventana de validez del derecho (feed del radar de
            // retención y del control de acceso). Sin vigencia, no expira por fecha.
            $validoHasta = VigenciaProducto::de($producto)?->hasta($inicio);

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
            // A qué clases aplica, copiado: editar el plan después no cambia lo vendido.
            $derecho->ofertas()->sync($producto->ofertas()->pluck('ofertas.id')->all());

            if (! $producto->ilimitado) {
                $concesion = $recurrente
                    ? ($producto->unidades_por_ciclo ?? 0)
                    : ($producto->creditos_incluidos ?? 0);

                if ($concesion > 0) {
                    $this->libro->registrar(
                        $derecho,
                        TipoMovimiento::Concesion,
                        $concesion,
                        $recurrente ? 'Concesión de ciclo' : 'Concesión inicial',
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
     * Zona horaria del negocio: con ella se decide la fecha de compra.
     */
    public function zona(): string
    {
        return (string) ($this->gestor->actual()?->zona_horaria ?: 'America/Mexico_City');
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
