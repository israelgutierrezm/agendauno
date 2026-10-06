<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use App\Modules\Tenancy\Pagos\MetodoPago;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Movimientos de dinero del negocio en un rango de fechas (en su zona horaria), con
 * quién hizo cada uno: cobros (en caja, en línea, pagos automáticos), devoluciones,
 * ventas de mostrador y cancelaciones de compras. Sirve de corte de caja.
 *
 * - Cada movimiento cuenta en la fecha en que el dinero se movió: un cobro, cuando se
 *   aprobó (`aprobado_en`: un pago iniciado ayer y confirmado hoy es de hoy); una
 *   devolución, cuando se hizo (`aplicado_en`).
 * - Los totales se calculan en la base sobre TODO el rango, no sobre las filas que se
 *   muestran (la lista trae a lo más `limite`, las más recientes, y avisa si se cortó).
 * - Nunca se suman monedas distintas: hay totales por moneda.
 * - Cada movimiento lleva su referencia y la de su operación de origen (orden, pago).
 *
 * Quién: el usuario que registró el cobro, hizo la devolución, vendió o canceló; si
 * no hubo uno, lo dice ("En línea", "Pago automático", "Sistema").
 *
 * Sucursales (R19): con `$sucursales` (las de un usuario acotado), lista, totales y
 * exportación solo cuentan lo de esas sedes: cobros y devoluciones por la sucursal de
 * su compra, ventas de mostrador por la suya. Lo que no tiene sede no es de ninguna.
 *
 * @phpstan-type Movimiento array{fecha: string, tipo: string, monto_minor: int, moneda: string, metodo: string|null, persona: string|null, concepto: string, quien: string, quien_id: string|null, referencia: string, orden: string|null, pago: string|null, detalle: string|null}
 * @phpstan-type Totales array{moneda: string, cobrado_minor: int, devuelto_minor: int, neto_minor: int, por_cobrar_minor: int, por_metodo: array<string, int>, por_usuario: list<array{quien: string, cobrado_minor: int, devuelto_minor: int}>}
 */
class MovimientosDePagoTenant
{
    public const LIMITE = 2000;

    /**
     * Estados de un pago cuyo dinero sí entró (aunque después se haya devuelto).
     */
    private const COBRADOS = [EstadoPago::Aprobado, EstadoPago::ParcialmenteReembolsado, EstadoPago::Reembolsado];

    /** @var list<int>|null Sedes a las que se acota la consulta en curso (null = todas). */
    private ?array $sucursales = null;

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    /**
     * Acota una consulta por la sede de la compra (`$columnaOrden`: la columna con el
     * id de la orden, p. ej. `pagos.orden_id`).
     *
     * @template TModelo of Model
     *
     * @param  Builder<TModelo>  $consulta
     * @return Builder<TModelo>
     */
    private function porSedeDeLaOrden(Builder $consulta, string $columnaOrden): Builder
    {
        if ($this->sucursales === null) {
            return $consulta;
        }
        $sedes = $this->sucursales;

        return $consulta->whereIn($columnaOrden, fn ($q) => $q->select('id')->from('ordenes')->whereIn('sucursal_id', $sedes));
    }

    /**
     * @param  string|null  $usuarioUlid  solo lo que hizo esa persona del equipo
     * @param  string|null  $tipo  cobro | devolucion | venta | cancelacion
     * @param  int|null  $limite  filas por tipo en la lista (null = todas, p. ej. para el CSV)
     * @param  list<int>|null  $sucursales  solo lo de estas sedes (null = todas)
     * @return array{movimientos: list<Movimiento>, totales: list<Totales>, truncado: bool}
     */
    public function listar(string $desde, string $hasta, ?string $usuarioUlid = null, ?string $tipo = null, ?int $limite = self::LIMITE, ?array $sucursales = null): array
    {
        $this->sucursales = $sucursales;
        $zona = (string) ($this->gestor->actual()?->zona_horaria ?: 'America/Mexico_City');
        $inicio = CarbonImmutable::parse($desde, $zona)->startOfDay()->utc();
        $fin = CarbonImmutable::parse($hasta, $zona)->endOfDay()->utc();

        $usuarioId = null;
        if ($usuarioUlid !== null && $usuarioUlid !== '') {
            $usuarioId = (int) (Usuario::withTrashed()->where('ulid', $usuarioUlid)->value('id') ?? 0);
        }
        $incluye = static fn (string $t): bool => $tipo === null || $tipo === '' || $tipo === $t;

        $movimientos = [];
        $truncado = false;
        foreach ([
            'cobro' => fn (): array => $this->cobros($inicio, $fin, $usuarioId, $limite),
            'devolucion' => fn (): array => $this->devoluciones($inicio, $fin, $usuarioId, $limite),
            'venta' => fn (): array => $this->ventas($inicio, $fin, $usuarioId, $limite),
            'cancelacion' => fn (): array => $this->cancelaciones($inicio, $fin, $usuarioId, $limite),
        ] as $t => $consulta) {
            if (! $incluye($t)) {
                continue;
            }
            [$filas, $cortado] = $consulta();
            $movimientos = [...$movimientos, ...$filas];
            $truncado = $truncado || $cortado;
        }

        usort($movimientos, static fn (array $a, array $b): int => strcmp($b['fecha'], $a['fecha']));

        return [
            'movimientos' => $movimientos,
            'totales' => $this->totales($inicio, $fin, $usuarioId, $incluye),
            'truncado' => $truncado,
        ];
    }

    /**
     * El dinero de un periodo por moneda, para reportes (nunca se suman monedas):
     * - ventas: lo vendido, por su fecha (compras no canceladas y ventas de mostrador);
     * - cobrado: el dinero que entró, por la fecha del cobro (incluye mostrador);
     * - devuelto: lo devuelto, por la fecha de la devolución (también parciales);
     * - neto: cobrado menos devuelto.
     *
     * @param  list<int>|null  $sucursales  solo lo de estas sedes (null = todas)
     * @return list<array{moneda: string, ventas_minor: int, cobrado_minor: int, devuelto_minor: int, neto_minor: int}>
     */
    public function porMoneda(string $desde, string $hasta, ?array $sucursales = null): array
    {
        $this->sucursales = $sucursales;
        $zona = (string) ($this->gestor->actual()?->zona_horaria ?: 'America/Mexico_City');
        $inicio = CarbonImmutable::parse($desde, $zona)->startOfDay()->utc();
        $fin = CarbonImmutable::parse($hasta, $zona)->endOfDay()->utc();

        /** @var array<string, array{moneda: string, ventas_minor: int, cobrado_minor: int, devuelto_minor: int, neto_minor: int}> $por */
        $por = [];
        $fila = static fn (string $moneda): array => ['moneda' => $moneda, 'ventas_minor' => 0, 'cobrado_minor' => 0, 'devuelto_minor' => 0, 'neto_minor' => 0];
        foreach ($this->totales($inicio, $fin, null, static fn (string $t): bool => true) as $t) {
            $por[$t['moneda']] = [...$fila($t['moneda']), 'cobrado_minor' => $t['cobrado_minor'], 'devuelto_minor' => $t['devuelto_minor'], 'neto_minor' => $t['neto_minor']];
        }

        $vendido = OrdenTenant::query()
            ->selectRaw('moneda, SUM(total_minor) AS total')
            ->when($sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $sucursales))
            ->where('estado', '!=', EstadoOrden::Cancelada->value)
            ->whereBetween('created_at', [$inicio, $fin])
            ->groupBy('moneda')
            ->toBase()
            ->get()
            ->concat(VentaPosTenant::query()
                ->selectRaw('moneda, SUM(total_minor) AS total')
                ->when($sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $sucursales))
                ->whereNull('anulada_en')
                ->whereBetween('created_at', [$inicio, $fin])
                ->groupBy('moneda')
                ->toBase()
                ->get());
        foreach ($vendido as $v) {
            $moneda = (string) $v->moneda;
            $por[$moneda] ??= $fila($moneda);
            $por[$moneda]['ventas_minor'] += (int) $v->total;
        }

        $lista = array_values($por);
        // Primero la moneda con más dinero (la principal del negocio).
        usort($lista, static fn (array $a, array $b): int => [$b['cobrado_minor'], $b['ventas_minor']] <=> [$a['cobrado_minor'], $a['ventas_minor']]);

        return $lista;
    }

    /**
     * El dinero de un periodo día por día (días del negocio) y por moneda, con las
     * mismas reglas que {@see porMoneda}: lo vendido por la fecha de la compra
     * (compras no canceladas y mostrador, y cuántas ventas); lo cobrado por la fecha
     * del cobro (con mostrador); lo devuelto por la fecha de la devolución.
     *
     * @param  list<int>|null  $sucursales  solo lo de estas sedes (null = todas)
     * @return array<string, array<string, array{ventas: int, ventas_minor: int, cobrado_minor: int, devuelto_minor: int}>> moneda → día (AAAA-MM-DD) → cifras
     */
    public function diario(string $desde, string $hasta, ?array $sucursales = null): array
    {
        $this->sucursales = $sucursales;
        $zona = (string) ($this->gestor->actual()?->zona_horaria ?: 'America/Mexico_City');
        $inicio = CarbonImmutable::parse($desde, $zona)->startOfDay()->utc();
        $fin = CarbonImmutable::parse($hasta, $zona)->endOfDay()->utc();

        /** @var array<string, array<string, array{ventas: int, ventas_minor: int, cobrado_minor: int, devuelto_minor: int}>> $por */
        $por = [];
        $sumar = static function (string $moneda, mixed $momento, string $campo, int $monto, bool $esVenta = false) use (&$por, $zona): void {
            if ($momento === null || $momento === '') {
                return;
            }
            $dia = CarbonImmutable::parse((string) $momento, 'UTC')->setTimezone($zona)->toDateString();
            $por[$moneda][$dia] ??= ['ventas' => 0, 'ventas_minor' => 0, 'cobrado_minor' => 0, 'devuelto_minor' => 0];
            $por[$moneda][$dia][$campo] += $monto;
            if ($esVenta) {
                $por[$moneda][$dia]['ventas']++;
            }
        };

        $compras = OrdenTenant::query()
            ->when($sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $sucursales))
            ->where('estado', '!=', EstadoOrden::Cancelada->value)
            ->whereBetween('created_at', [$inicio, $fin])
            ->toBase()
            ->get(['moneda', 'total_minor', 'created_at']);
        foreach ($compras as $o) {
            $sumar((string) $o->moneda, $o->created_at, 'ventas_minor', (int) $o->total_minor, true);
        }

        // Mostrador: se vende y se cobra en el momento.
        $mostrador = VentaPosTenant::query()
            ->whereNull('anulada_en')
            ->when($sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $sucursales))
            ->whereBetween('created_at', [$inicio, $fin])
            ->toBase()
            ->get(['moneda', 'total_minor', 'created_at']);
        foreach ($mostrador as $v) {
            $sumar((string) $v->moneda, $v->created_at, 'ventas_minor', (int) $v->total_minor, true);
            $sumar((string) $v->moneda, $v->created_at, 'cobrado_minor', (int) $v->total_minor);
        }

        $cobros = $this->porSedeDeLaOrden(PagoTenant::query(), 'pagos.orden_id')
            ->whereIn('estado', array_map(static fn (EstadoPago $e): string => $e->value, self::COBRADOS))
            ->whereBetween('aprobado_en', [$inicio, $fin])
            ->toBase()
            ->get(['moneda', 'monto_minor', 'aprobado_en']);
        foreach ($cobros as $p) {
            $sumar((string) $p->moneda, $p->aprobado_en, 'cobrado_minor', (int) $p->monto_minor);
        }

        $devoluciones = $this->porSedeDeLaOrden(ReembolsoTenant::query(), 'pagos.orden_id')
            ->join('pagos', 'pagos.id', '=', 'reembolsos.pago_id')
            ->where('reembolsos.estado', EstadoReembolso::Aprobado->value)
            ->whereBetween('reembolsos.aplicado_en', [$inicio, $fin])
            ->toBase()
            ->get(['reembolsos.moneda as moneda', 'reembolsos.monto_minor as monto', 'reembolsos.aplicado_en as fecha']);
        foreach ($devoluciones as $r) {
            $sumar((string) $r->moneda, $r->fecha, 'devuelto_minor', (int) $r->monto);
        }

        return $por;
    }

    /**
     * @return array{0: list<Movimiento>, 1: bool}
     */
    private function cobros(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $usuarioId, ?int $limite): array
    {
        $pagos = $this->porSedeDeLaOrden(PagoTenant::query(), 'pagos.orden_id')
            ->whereIn('estado', array_map(static fn (EstadoPago $e): string => $e->value, self::COBRADOS))
            ->whereBetween('aprobado_en', [$inicio, $fin])
            ->when($usuarioId !== null, fn ($q) => $q->where('registrado_por', $usuarioId))
            ->with(['orden.persona', 'orden.lineas.producto'])
            ->orderByDesc('aprobado_en')
            ->orderByDesc('id')
            ->when($limite !== null, fn ($q) => $q->limit($limite + 1))
            ->get();
        [$pagos, $cortado] = self::recortar($pagos->all(), $limite);
        $nombres = $this->nombres(array_map(static fn (PagoTenant $p): mixed => $p->registrado_por, $pagos));

        return [array_map(function (PagoTenant $pago) use ($nombres): array {
            $registro = $pago->registrado_por !== null ? $nombres[(int) $pago->registrado_por] ?? null : null;

            return [
                'fecha' => (string) $pago->aprobado_en?->toIso8601String(),
                'tipo' => 'cobro',
                'monto_minor' => $pago->monto_minor,
                'moneda' => $pago->moneda,
                'metodo' => $pago->metodo->value ?? $pago->proveedor,
                'persona' => $pago->orden?->persona?->nombreCompleto(),
                'concepto' => self::concepto($pago->orden),
                'quien' => $registro['nombre'] ?? ($pago->domiciliacion_id !== null ? 'Pago automático' : 'En línea'),
                'quien_id' => $registro['ulid'] ?? null,
                'referencia' => (string) $pago->ulid,
                'orden' => $pago->orden?->ulid,
                'pago' => (string) $pago->ulid,
                'detalle' => $pago->proveedor,
            ];
        }, $pagos), $cortado];
    }

    /**
     * @return array{0: list<Movimiento>, 1: bool}
     */
    private function devoluciones(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $usuarioId, ?int $limite): array
    {
        $reembolsos = ReembolsoTenant::query()
            ->when($this->sucursales !== null, fn ($q) => $q->whereIn('pago_id', fn ($p) => $p->select('pagos.id')->from('pagos')
                ->whereIn('pagos.orden_id', fn ($o) => $o->select('id')->from('ordenes')->whereIn('sucursal_id', $this->sucursales ?? []))))
            ->where('estado', EstadoReembolso::Aprobado->value)
            ->whereBetween('aplicado_en', [$inicio, $fin])
            ->when($usuarioId !== null, fn ($q) => $q->where('actor_id', $usuarioId))
            ->with(['pago.orden.persona', 'pago.orden.lineas.producto'])
            ->orderByDesc('aplicado_en')
            ->orderByDesc('id')
            ->when($limite !== null, fn ($q) => $q->limit($limite + 1))
            ->get();
        [$reembolsos, $cortado] = self::recortar($reembolsos->all(), $limite);
        $nombres = $this->nombres(array_map(static fn (ReembolsoTenant $r): mixed => $r->actor_id, $reembolsos));

        return [array_map(function (ReembolsoTenant $r) use ($nombres): array {
            $actor = $r->actor_id !== null ? $nombres[(int) $r->actor_id] ?? null : null;

            return [
                'fecha' => (string) $r->aplicado_en?->toIso8601String(),
                'tipo' => 'devolucion',
                'monto_minor' => -$r->monto_minor,
                'moneda' => $r->moneda,
                'metodo' => $r->pago->metodo->value ?? $r->pago->proveedor ?? null,
                'persona' => $r->pago?->orden?->persona?->nombreCompleto(),
                'concepto' => self::concepto($r->pago?->orden),
                'quien' => $actor['nombre'] ?? ($r->actor_nombre ?: 'Sistema'),
                'quien_id' => $actor['ulid'] ?? null,
                'referencia' => (string) $r->ulid,
                'orden' => $r->pago?->orden?->ulid,
                'pago' => $r->pago?->ulid,
                'detalle' => $r->motivo,
            ];
        }, $reembolsos), $cortado];
    }

    /**
     * @return array{0: list<Movimiento>, 1: bool}
     */
    private function ventas(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $usuarioId, ?int $limite): array
    {
        $ventas = VentaPosTenant::query()
            // Una venta anulada nunca ocurrió (ADR 0089).
            ->whereNull('anulada_en')
            ->when($this->sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $this->sucursales))
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($usuarioId !== null, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->with('lineas.articulo')
            ->orderByDesc('id')
            ->when($limite !== null, fn ($q) => $q->limit($limite + 1))
            ->get();
        [$ventas, $cortado] = self::recortar($ventas->all(), $limite);
        $nombres = $this->nombres(array_map(static fn (VentaPosTenant $v): mixed => $v->usuario_id, $ventas));

        return [array_map(function (VentaPosTenant $v) use ($nombres): array {
            $vendio = $v->usuario_id !== null ? $nombres[(int) $v->usuario_id] ?? null : null;
            $concepto = $v->lineas->map(fn ($l): string => (string) ($l->articulo->nombre ?? ''))->filter()->unique()->implode(', ');

            return [
                'fecha' => (string) $v->created_at?->toIso8601String(),
                'tipo' => 'venta',
                'monto_minor' => $v->total_minor,
                'moneda' => $v->moneda,
                'metodo' => $v->metodo_pago,
                'persona' => null,
                'concepto' => Str::limit($concepto !== '' ? $concepto : 'Venta de mostrador', 120),
                'quien' => $vendio['nombre'] ?? 'Sistema',
                'quien_id' => $vendio['ulid'] ?? null,
                'referencia' => (string) $v->ulid,
                'orden' => null,
                'pago' => null,
                'detalle' => null,
            ];
        }, $ventas), $cortado];
    }

    /**
     * Compras canceladas (no mueven dinero; se listan para saber quién y cuándo).
     *
     * @return array{0: list<Movimiento>, 1: bool}
     */
    private function cancelaciones(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $usuarioId, ?int $limite): array
    {
        $ordenes = OrdenTenant::query()
            ->when($this->sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $this->sucursales))
            ->where('estado', EstadoOrden::Cancelada->value)
            ->whereBetween('cancelada_en', [$inicio, $fin])
            ->when($usuarioId !== null, fn ($q) => $q->where('cancelada_por', $usuarioId))
            ->with(['persona', 'lineas.producto'])
            ->orderByDesc('cancelada_en')
            ->orderByDesc('id')
            ->when($limite !== null, fn ($q) => $q->limit($limite + 1))
            ->get();
        [$ordenes, $cortado] = self::recortar($ordenes->all(), $limite);
        $nombres = $this->nombres(array_map(static fn (OrdenTenant $o): mixed => $o->cancelada_por, $ordenes));

        return [array_map(function (OrdenTenant $o) use ($nombres): array {
            $cancelo = $o->cancelada_por !== null ? $nombres[(int) $o->cancelada_por] ?? null : null;

            return [
                'fecha' => (string) $o->cancelada_en?->toIso8601String(),
                'tipo' => 'cancelacion',
                'monto_minor' => 0,
                'moneda' => $o->moneda,
                'metodo' => null,
                'persona' => $o->persona?->nombreCompleto(),
                'concepto' => self::concepto($o),
                'quien' => $cancelo['nombre'] ?? 'Sistema',
                'quien_id' => $cancelo['ulid'] ?? null,
                'referencia' => (string) $o->ulid,
                'orden' => (string) $o->ulid,
                'pago' => null,
                'detalle' => 'Total '.number_format($o->total_minor / 100, 2, '.', ','),
            ];
        }, $ordenes), $cortado];
    }

    /**
     * Totales del rango COMPLETO, calculados en la base y separados por moneda: lo
     * cobrado (por método y por quién), lo devuelto, el neto y lo que sigue por cobrar
     * (compras del rango aún pendientes de pago).
     *
     * @param  callable(string): bool  $incluye
     * @return list<Totales>
     */
    private function totales(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $usuarioId, callable $incluye): array
    {
        /** @var array<string, array{moneda: string, cobrado_minor: int, devuelto_minor: int, neto_minor: int, por_cobrar_minor: int, por_metodo: array<string, int>, por_usuario: array<string, array{quien: string, cobrado_minor: int, devuelto_minor: int}>}> $por */
        $por = [];
        $sumar = function (string $moneda, ?string $metodo, int $monto, string $quien) use (&$por): void {
            $por[$moneda] ??= ['moneda' => $moneda, 'cobrado_minor' => 0, 'devuelto_minor' => 0, 'neto_minor' => 0, 'por_cobrar_minor' => 0, 'por_metodo' => [], 'por_usuario' => []];
            $etiqueta = MetodoPago::tryFrom((string) $metodo)?->etiqueta() ?? ucfirst($metodo !== null && $metodo !== '' ? $metodo : 'Otro');
            $por[$moneda]['por_metodo'][$etiqueta] = ($por[$moneda]['por_metodo'][$etiqueta] ?? 0) + $monto;
            $por[$moneda]['por_usuario'][$quien] ??= ['quien' => $quien, 'cobrado_minor' => 0, 'devuelto_minor' => 0];
            if ($monto >= 0) {
                $por[$moneda]['cobrado_minor'] += $monto;
                $por[$moneda]['por_usuario'][$quien]['cobrado_minor'] += $monto;
            } else {
                $por[$moneda]['devuelto_minor'] += -$monto;
                $por[$moneda]['por_usuario'][$quien]['devuelto_minor'] += -$monto;
            }
        };

        if ($incluye('cobro')) {
            $filas = $this->porSedeDeLaOrden(PagoTenant::query(), 'pagos.orden_id')
                ->selectRaw('moneda, metodo, proveedor, registrado_por, CASE WHEN domiciliacion_id IS NULL THEN 0 ELSE 1 END AS automatico, SUM(monto_minor) AS total')
                ->whereIn('estado', array_map(static fn (EstadoPago $e): string => $e->value, self::COBRADOS))
                ->whereBetween('aprobado_en', [$inicio, $fin])
                ->when($usuarioId !== null, fn ($q) => $q->where('registrado_por', $usuarioId))
                ->groupByRaw('moneda, metodo, proveedor, registrado_por, CASE WHEN domiciliacion_id IS NULL THEN 0 ELSE 1 END')
                ->toBase()
                ->get();
            $nombres = $this->nombres($filas->pluck('registrado_por')->all());
            foreach ($filas as $f) {
                $quien = $f->registrado_por !== null
                    ? ($nombres[(int) $f->registrado_por]['nombre'] ?? 'Sistema')
                    : ((int) $f->automatico === 1 ? 'Pago automático' : 'En línea');
                $sumar((string) $f->moneda, ($f->metodo ?? null) ?: ($f->proveedor ?? null), (int) $f->total, $quien);
            }
        }

        if ($incluye('devolucion')) {
            $filas = $this->porSedeDeLaOrden(ReembolsoTenant::query(), 'pagos.orden_id')
                ->join('pagos', 'pagos.id', '=', 'reembolsos.pago_id')
                ->selectRaw('reembolsos.moneda AS moneda, pagos.metodo AS metodo, pagos.proveedor AS proveedor, reembolsos.actor_id AS actor_id, reembolsos.actor_nombre AS actor_nombre, SUM(reembolsos.monto_minor) AS total')
                ->where('reembolsos.estado', EstadoReembolso::Aprobado->value)
                ->whereBetween('reembolsos.aplicado_en', [$inicio, $fin])
                ->when($usuarioId !== null, fn ($q) => $q->where('reembolsos.actor_id', $usuarioId))
                ->groupBy('reembolsos.moneda', 'pagos.metodo', 'pagos.proveedor', 'reembolsos.actor_id', 'reembolsos.actor_nombre')
                ->toBase()
                ->get();
            $nombres = $this->nombres($filas->pluck('actor_id')->all());
            foreach ($filas as $f) {
                $quien = $f->actor_id !== null
                    ? ($nombres[(int) $f->actor_id]['nombre'] ?? 'Sistema')
                    : ((string) ($f->actor_nombre ?? '') !== '' ? (string) $f->actor_nombre : 'Sistema');
                $sumar((string) $f->moneda, ($f->metodo ?? null) ?: ($f->proveedor ?? null), -((int) $f->total), $quien);
            }
        }

        if ($incluye('venta')) {
            $filas = VentaPosTenant::query()
                ->selectRaw('moneda, metodo_pago, usuario_id, SUM(total_minor) AS total')
                ->whereNull('anulada_en')
                ->when($this->sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $this->sucursales))
                ->whereBetween('created_at', [$inicio, $fin])
                ->when($usuarioId !== null, fn ($q) => $q->where('usuario_id', $usuarioId))
                ->groupBy('moneda', 'metodo_pago', 'usuario_id')
                ->toBase()
                ->get();
            $nombres = $this->nombres($filas->pluck('usuario_id')->all());
            foreach ($filas as $f) {
                $quien = $f->usuario_id !== null ? ($nombres[(int) $f->usuario_id]['nombre'] ?? 'Sistema') : 'Sistema';
                $sumar((string) $f->moneda, $f->metodo_pago ?? null, (int) $f->total, $quien);
            }
        }

        // Lo que sigue por cobrar: compras del rango aún pendientes de pago.
        $pendientes = OrdenTenant::query()
            ->selectRaw('moneda, SUM(total_minor) AS total')
            ->when($this->sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $this->sucursales))
            ->where('estado', EstadoOrden::Pendiente->value)
            ->whereBetween('created_at', [$inicio, $fin])
            ->groupBy('moneda')
            ->toBase()
            ->get();
        foreach ($pendientes as $f) {
            $moneda = (string) $f->moneda;
            $por[$moneda] ??= ['moneda' => $moneda, 'cobrado_minor' => 0, 'devuelto_minor' => 0, 'neto_minor' => 0, 'por_cobrar_minor' => 0, 'por_metodo' => [], 'por_usuario' => []];
            $por[$moneda]['por_cobrar_minor'] = (int) $f->total;
        }

        $totales = [];
        foreach ($por as $t) {
            $t['neto_minor'] = $t['cobrado_minor'] - $t['devuelto_minor'];
            $t['por_usuario'] = array_values($t['por_usuario']);
            $totales[] = $t;
        }
        // Primero la moneda con más movimiento (la principal del negocio).
        usort($totales, static fn (array $a, array $b): int => $b['cobrado_minor'] <=> $a['cobrado_minor']);

        return $totales;
    }

    /**
     * Deja a lo más `$limite` filas y dice si había más.
     *
     * @template T
     *
     * @param  list<T>  $filas
     * @return array{0: list<T>, 1: bool}
     */
    private static function recortar(array $filas, ?int $limite): array
    {
        if ($limite === null || count($filas) <= $limite) {
            return [$filas, false];
        }

        return [array_slice($filas, 0, $limite), true];
    }

    /**
     * Nombre y ulid de cada usuario (aunque esté dado de baja).
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, array{nombre: string, ulid: string}>
     */
    private function nombres(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $nombres = [];
        foreach (Usuario::withTrashed()->whereIn('id', $ids)->get(['id', 'ulid', 'name']) as $u) {
            $nombres[(int) $u->getKey()] = ['nombre' => (string) $u->name, 'ulid' => (string) $u->ulid];
        }

        return $nombres;
    }

    private static function concepto(?OrdenTenant $orden): string
    {
        $nombres = $orden?->lineas
            ->map(static fn (LineaOrdenTenant $l): ?string => $l->producto?->nombre)
            ->filter()
            ->unique()
            ->implode(', ');

        if (is_string($nombres) && $nombres !== '') {
            return Str::limit($nombres, 120);
        }

        return $orden?->sesion_id !== null ? 'Cita' : ($orden?->renueva_acuerdo_id !== null ? 'Renovación' : 'Compra');
    }
}
