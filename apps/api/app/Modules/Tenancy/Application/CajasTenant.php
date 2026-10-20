<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\CajaCerrada;
use App\Modules\Tenancy\Exceptions\CajaNoOperable;
use App\Modules\Tenancy\Exceptions\CajaYaAbierta;
use App\Modules\Tenancy\Models\ConteoCajaTenant;
use App\Modules\Tenancy\Models\MovimientoCajaTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\TurnoCajaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pagos\PartesCobro;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cierre y arqueo de caja (ADR 0175). Cada sucursal abre su caja por turno con un
 * fondo; cada movimiento de mostrador de la sede (cobro, venta, propina, devolución,
 * anulación, corrección de la forma de pago, retiro o ingreso de efectivo) queda en
 * el libro de su caja abierta; al cerrar se cuenta a ciegas y, si no cuadra, se pide
 * el motivo y el cierre queda para que el admin lo revise.
 *
 * - **Esperado** de cada forma de pago = el fondo (solo efectivo) + lo asentado.
 * - **Lo cerrado no cambia**: una anulación, corrección o devolución posterior se
 *   asienta en la caja abierta del momento, con la referencia al cobro original.
 * - **Solo en sedes que usan caja** (abrieron alguna): ahí, mover dinero de mostrador
 *   pide su caja abierta. Las demás cobran como siempre.
 * - **Concurrencia**: quien mueve dinero bloquea la caja (`turnoParaMovimiento`)
 *   dentro de su transacción, después de sus propias filas y ANTES del inventario
 *   (una venta bloquea la caja y luego los artículos; así nadie espera en orden
 *   cruzado). Contar bloquea la misma fila: un cobro entra antes del conteo o espera
 *   y encuentra la caja contada.
 */
class CajasTenant
{
    /** Formas de pago de la caja: el efectivo se cuenta; las demás se declaran. */
    public const METODOS = ['efectivo', 'tarjeta', 'transferencia', 'ventanilla'];

    public function __construct(
        private readonly RegionNegocioTenant $region,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** La forma de pago de la caja de lo que guardan los pagos y las ventas. */
    public static function metodo(?string $metodo): string
    {
        return match ($metodo) {
            'efectivo' => 'efectivo',
            'spei', 'transferencia' => 'transferencia',
            'ventanilla' => 'ventanilla',
            // La terminal del negocio («manual» es como lo mandaban las citas y la app).
            default => 'tarjeta',
        };
    }

    /** La forma de pago de la caja de un pago (uno registrado como «efectivo» puede no traerla). */
    public static function metodoDelPago(PagoTenant $pago): string
    {
        return self::metodo($pago->metodo->value ?? ($pago->proveedor === 'efectivo' ? 'efectivo' : null));
    }

    /**
     * Cuánto entró en cada forma con un pago: sus partes si fue mixto (ADR 0177); si no,
     * todo en su forma.
     *
     * @return list<array{metodo: string, monto_minor: int}>
     */
    public static function partesDelPago(PagoTenant $pago): array
    {
        return $pago->metodo === MetodoPago::Mixto
            ? PartesCobro::leer($pago->partes())
            : [['metodo' => self::metodoDelPago($pago), 'monto_minor' => (int) $pago->monto_minor]];
    }

    /**
     * Cuánto entró en cada forma con una venta del mostrador (lo vendido y la propina):
     * sus partes si fue mixta; si no, todo en su forma. Sin dinero propio, nada.
     *
     * @return list<array{metodo: string, monto_minor: int}>
     */
    public static function partesDeVenta(VentaPosTenant $venta): array
    {
        $monto = (int) $venta->total_minor + (int) $venta->propina_minor;
        if ($venta->metodo_pago === PartesCobro::MIXTO) {
            return PartesCobro::leer($venta->partes());
        }

        return $monto > 0 ? [['metodo' => self::metodo($venta->metodo_pago), 'monto_minor' => $monto]] : [];
    }

    /**
     * Las formas de todo el cobro de una venta: en una cita, también el pago del
     * servicio. Sirve para el ticket y el cambio (que sale solo del efectivo).
     *
     * @return list<array{metodo: string, monto_minor: int}>
     */
    public static function partesDelCobro(VentaPosTenant $venta): array
    {
        $delServicio = [];
        if ($venta->orden_id !== null && (int) $venta->servicio_minor > 0) {
            // El cobro de esa venta: aprobado (no anulado) y hecho a más tardar con ella, no
            // uno posterior.
            $pago = PagoTenant::query()
                ->where('orden_id', $venta->orden_id)
                ->whereIn('estado', [EstadoPago::Aprobado->value, EstadoPago::ParcialmenteReembolsado->value, EstadoPago::Reembolsado->value])
                ->whereNotNull('aprobado_en')
                ->where('created_at', '<=', $venta->created_at)
                ->latest('id')
                ->first();
            $delServicio = $pago instanceof PagoTenant ? self::partesDelPago($pago) : [];
        }

        return PartesCobro::juntar([...$delServicio, ...self::partesDeVenta($venta)]);
    }

    /**
     * La caja abierta de la sede, bloqueada, para asentar un movimiento; null si la sede
     * no usa caja. Sin sede (una orden sin sucursal), la única del negocio.
     *
     * @throws CajaCerrada si la sede usa caja y no tiene una abierta
     * @throws CajaNoOperable si no se sabe en qué sede (varias, y alguna usa caja)
     */
    public function turnoParaMovimiento(?int $sucursalId): ?TurnoCajaTenant
    {
        if (! TurnoCajaTenant::query()->exists()) {
            return null;
        }
        if ($sucursalId === null) {
            $sedes = SucursalTenant::query()->limit(2)->pluck('id');
            if ($sedes->count() !== 1) {
                throw new CajaNoOperable('Elige en la barra la sucursal donde cobras: su caja registra este movimiento.');
            }
            $sucursalId = (int) $sedes->first();
        }
        if (! TurnoCajaTenant::query()->where('sucursal_id', $sucursalId)->exists()) {
            return null;
        }

        // Se bloquea por su clave, como al contar y al cerrar. Bloquearla por el índice de
        // la sede abierta cruzaría los bloqueos con el cierre, que cambia ese índice, y
        // MySQL abortaría una de las dos (deadlock). Ya bloqueada, se vuelve a revisar.
        $id = TurnoCajaTenant::query()->where('sucursal_abierta', $sucursalId)->value('id');
        $turno = $id !== null ? TurnoCajaTenant::query()->whereKey($id)->lockForUpdate()->first() : null;
        if (! $turno instanceof TurnoCajaTenant || $turno->estado !== TurnoCajaTenant::ABIERTA) {
            $sede = SucursalTenant::query()->whereKey($sucursalId)->value('nombre');
            throw new CajaCerrada($turno?->estado === TurnoCajaTenant::CONTADA
                ? "La caja de {$sede} ya se contó: termina su cierre y abre otra para seguir."
                : "Abre la caja de {$sede} para mover dinero en el mostrador.");
        }

        return $turno;
    }

    /**
     * Asienta un cobro mixto (ADR 0177): un renglón por forma, con el signo pedido.
     *
     * @param  list<array{metodo: string, monto_minor: int}>  $partes
     * @param  array{pago_id?: int|null, venta_pos_id?: int|null, reembolso_id?: int|null}  $origen
     */
    public function asentarPartes(?TurnoCajaTenant $turno, string $tipo, array $partes, int $signo, array $origen = [], ?Usuario $actor = null, ?string $motivo = null): void
    {
        foreach ($partes as $parte) {
            $this->asentar($turno, $tipo, $parte['metodo'], $signo * $parte['monto_minor'], $origen, $actor, $motivo);
        }
    }

    /**
     * Asienta un renglón en la caja (con signo). Sin caja (la sede no la usa) o en cero,
     * no hace nada.
     *
     * @param  array{pago_id?: int|null, venta_pos_id?: int|null, reembolso_id?: int|null}  $origen
     */
    public function asentar(?TurnoCajaTenant $turno, string $tipo, string $metodo, int $montoMinor, array $origen = [], ?Usuario $actor = null, ?string $motivo = null): void
    {
        if ($turno === null || $montoMinor === 0) {
            return;
        }
        MovimientoCajaTenant::query()->create([
            'turno_caja_id' => $turno->getKey(),
            'tipo' => $tipo,
            'metodo' => $metodo,
            'monto_minor' => $montoMinor,
            'pago_id' => $origen['pago_id'] ?? null,
            'venta_pos_id' => $origen['venta_pos_id'] ?? null,
            'reembolso_id' => $origen['reembolso_id'] ?? null,
            'usuario_id' => $actor?->getKey(),
            'motivo' => $motivo !== null ? mb_substr($motivo, 0, 300) : null,
        ]);
    }

    /**
     * La caja abierta donde se ajusta un cobro (anularlo o corregir su forma de pago):
     * solo si ese cobro entró por una caja. Uno que no pasó por caja (p. ej. la sede aún
     * no la usaba) no se ajusta en la de hoy: su dinero nunca estuvo en ese cajón.
     */
    public function turnoParaAjusteDelCobro(PagoTenant $pago): ?TurnoCajaTenant
    {
        $entro = MovimientoCajaTenant::query()
            ->where('pago_id', $pago->getKey())
            ->where('tipo', MovimientoCajaTenant::COBRO)
            ->exists();

        return $entro ? $this->turnoParaMovimiento($this->sucursalDelCobro($pago)) : null;
    }

    /**
     * Igual, para una venta del mostrador: solo si entró por una caja.
     */
    public function turnoParaAjusteDeLaVenta(VentaPosTenant $venta): ?TurnoCajaTenant
    {
        $entro = MovimientoCajaTenant::query()
            ->where('venta_pos_id', $venta->getKey())
            ->where('tipo', MovimientoCajaTenant::VENTA)
            ->exists();

        return $entro ? $this->turnoParaMovimiento((int) $venta->sucursal_id) : null;
    }

    /**
     * La sede de la caja donde entró un cobro (un ajuste posterior va a la caja abierta
     * de esa misma sede); si no entró por una caja, la de su orden.
     */
    public function sucursalDelCobro(PagoTenant $pago): ?int
    {
        $turno = MovimientoCajaTenant::query()
            ->where('pago_id', $pago->getKey())
            ->where('tipo', MovimientoCajaTenant::COBRO)
            ->value('turno_caja_id');
        if ($turno !== null) {
            return (int) TurnoCajaTenant::query()->whereKey($turno)->value('sucursal_id');
        }
        $sucursal = $pago->orden()->value('sucursal_id');

        return $sucursal !== null ? (int) $sucursal : null;
    }

    public function abrir(SucursalTenant $sucursal, int $fondoMinor, ?string $nota, Usuario $actor): TurnoCajaTenant
    {
        try {
            $turno = TurnoCajaTenant::query()->create([
                'sucursal_id' => $sucursal->getKey(),
                'sucursal_abierta' => $sucursal->getKey(),
                'estado' => TurnoCajaTenant::ABIERTA,
                'moneda' => $this->region->moneda(),
                'fondo_minor' => max(0, $fondoMinor),
                'nota_apertura' => $nota !== null && trim($nota) !== '' ? mb_substr(trim($nota), 0, 300) : null,
                'abierta_por' => $actor->getKey(),
                'abierta_en' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new CajaYaAbierta("La caja de {$sucursal->nombre} sigue abierta o falta cerrarla.");
        }
        $this->auditoria->registrar($actor, 'caja.abierta', 'turno_caja', (string) $turno->ulid, null, [
            'sucursal' => $sucursal->nombre, 'fondo_minor' => $turno->fondo_minor, 'moneda' => $turno->moneda,
        ]);

        return $turno;
    }

    /** Un retiro o un ingreso de efectivo que no es venta (con su motivo). */
    /**
     * @param  string|null  $clave  la del intento: repetirlo (sin red, doble clic) devuelve el mismo movimiento
     */
    public function moverEfectivo(TurnoCajaTenant $turno, string $tipo, int $montoMinor, string $motivo, Usuario $actor, ?string $clave = null): MovimientoCajaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($turno, $tipo, $montoMinor, $motivo, $actor, $clave): MovimientoCajaTenant {
            $bloqueado = TurnoCajaTenant::query()->whereKey($turno->getKey())->lockForUpdate()->firstOrFail();
            $monto = $tipo === MovimientoCajaTenant::RETIRO ? -abs($montoMinor) : abs($montoMinor);
            $repetido = $clave !== null
                ? MovimientoCajaTenant::query()->where('turno_caja_id', $bloqueado->getKey())->where('clave', $clave)->first()
                : null;
            if ($repetido instanceof MovimientoCajaTenant) {
                if ($repetido->tipo !== $tipo || (int) $repetido->monto_minor !== $monto) {
                    throw new CajaNoOperable('Esa clave ya se usó para otro movimiento: vuelve a intentarlo.');
                }

                return $repetido;
            }
            if ($bloqueado->estado !== TurnoCajaTenant::ABIERTA) {
                throw new CajaNoOperable('Esta caja ya se contó: el efectivo se mueve en la caja abierta.');
            }
            $movimiento = MovimientoCajaTenant::query()->create([
                'turno_caja_id' => $bloqueado->getKey(),
                'tipo' => $tipo,
                'metodo' => 'efectivo',
                'monto_minor' => $monto,
                'usuario_id' => $actor->getKey(),
                'motivo' => mb_substr($motivo, 0, 300),
                'clave' => $clave,
            ]);
            $this->auditoria->registrar($actor, 'caja.'.$tipo, 'turno_caja', (string) $bloqueado->ulid, null, [
                'monto_minor' => abs($montoMinor), 'moneda' => $bloqueado->moneda,
            ], $motivo);

            return $movimiento;
        });
    }

    /**
     * Lo esperado de cada forma de pago: el fondo (efectivo) más lo asentado.
     *
     * @return array<string, int>
     */
    public function esperado(TurnoCajaTenant $turno): array
    {
        $esperado = array_fill_keys(self::METODOS, 0);
        $esperado['efectivo'] = $turno->fondo_minor;
        $sumas = MovimientoCajaTenant::query()
            ->where('turno_caja_id', $turno->getKey())
            ->groupBy('metodo')
            ->selectRaw('metodo, SUM(monto_minor) as total')
            ->pluck('total', 'metodo');
        foreach ($sumas as $metodo => $total) {
            $esperado[(string) $metodo] = ($esperado[(string) $metodo] ?? 0) + (int) $total;
        }

        return $esperado;
    }

    /**
     * El conteo a ciegas: lo contado de cada forma de pago contra lo esperado en ese
     * momento, una sola vez. Si todo cuadra, la caja se cierra; si no, queda «contada» y
     * falta el motivo (`cerrarConMotivo`).
     *
     * @param  array<string, int>  $contado  por forma de pago (lo que falte cuenta 0)
     */
    public function contar(TurnoCajaTenant $turno, array $contado, Usuario $actor): TurnoCajaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($turno, $contado, $actor): TurnoCajaTenant {
            $bloqueado = TurnoCajaTenant::query()->whereKey($turno->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado !== TurnoCajaTenant::ABIERTA) {
                throw new CajaNoOperable('Esta caja ya se contó: el conteo se registra una sola vez.');
            }

            $diferencia = 0;
            foreach ($this->esperado($bloqueado) as $metodo => $esperado) {
                // Fuera del efectivo el reporte puede salir negativo (una devolución por la
                // terminal en un día sin ventas con tarjeta).
                $real = $metodo === 'efectivo' ? max(0, (int) ($contado[$metodo] ?? 0)) : (int) ($contado[$metodo] ?? 0);
                $diferencia += abs($real - $esperado);
                ConteoCajaTenant::query()->create([
                    'turno_caja_id' => $bloqueado->getKey(),
                    'metodo' => $metodo,
                    'esperado_minor' => $esperado,
                    'contado_minor' => $real,
                    'diferencia_minor' => $real - $esperado,
                ]);
            }
            $neta = (int) ConteoCajaTenant::query()->where('turno_caja_id', $bloqueado->getKey())->sum('diferencia_minor');

            if ($diferencia === 0) {
                $bloqueado->update([
                    'estado' => TurnoCajaTenant::CERRADA,
                    'sucursal_abierta' => null,
                    'contada_por' => $actor->getKey(),
                    'contada_en' => now(),
                    'cerrada_por' => $actor->getKey(),
                    'cerrada_en' => now(),
                    'diferencia_minor' => 0,
                ]);
                $this->auditoria->registrar($actor, 'caja.cerrada', 'turno_caja', (string) $bloqueado->ulid, null, [
                    'diferencia_minor' => 0, 'moneda' => $bloqueado->moneda,
                ]);
            } else {
                $bloqueado->update([
                    'estado' => TurnoCajaTenant::CONTADA,
                    'contada_por' => $actor->getKey(),
                    'contada_en' => now(),
                    'diferencia_minor' => $neta,
                ]);
                $this->auditoria->registrar($actor, 'caja.contada', 'turno_caja', (string) $bloqueado->ulid, null, [
                    'diferencia_minor' => $neta, 'moneda' => $bloqueado->moneda,
                ]);
            }

            return $bloqueado->refresh();
        });
    }

    /** Cierra la caja contada que no cuadró, con el motivo; queda para revisión. */
    public function cerrarConMotivo(TurnoCajaTenant $turno, string $motivo, Usuario $actor): TurnoCajaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($turno, $motivo, $actor): TurnoCajaTenant {
            $bloqueado = TurnoCajaTenant::query()->whereKey($turno->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado !== TurnoCajaTenant::CONTADA) {
                throw new CajaNoOperable($bloqueado->estado === TurnoCajaTenant::CERRADA
                    ? 'Esta caja ya está cerrada.'
                    : 'Primero cuenta la caja.');
            }
            $bloqueado->update([
                'estado' => TurnoCajaTenant::CERRADA,
                'sucursal_abierta' => null,
                'cerrada_por' => $actor->getKey(),
                'cerrada_en' => now(),
                'motivo_diferencia' => mb_substr(trim($motivo), 0, 500),
                'requiere_revision' => true,
            ]);
            $this->auditoria->registrar($actor, 'caja.cerrada', 'turno_caja', (string) $bloqueado->ulid, null, [
                'diferencia_minor' => $bloqueado->diferencia_minor, 'moneda' => $bloqueado->moneda,
            ], $motivo);

            return $bloqueado->refresh();
        });
    }

    /** El admin revisa la diferencia de un cierre. */
    public function revisar(TurnoCajaTenant $turno, string $nota, Usuario $actor): TurnoCajaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($turno, $nota, $actor): TurnoCajaTenant {
            $bloqueado = TurnoCajaTenant::query()->whereKey($turno->getKey())->lockForUpdate()->firstOrFail();
            if (! $bloqueado->requiere_revision || $bloqueado->revisada_en !== null) {
                throw new CajaNoOperable('Este cierre no tiene una diferencia por revisar.');
            }
            $bloqueado->update([
                'revisada_por' => $actor->getKey(),
                'revisada_en' => now(),
                'nota_revision' => mb_substr(trim($nota), 0, 500),
            ]);
            $this->auditoria->registrar($actor, 'caja.revisada', 'turno_caja', (string) $bloqueado->ulid, null, [
                'diferencia_minor' => $bloqueado->diferencia_minor,
            ], $nota);

            return $bloqueado->refresh();
        });
    }
}
