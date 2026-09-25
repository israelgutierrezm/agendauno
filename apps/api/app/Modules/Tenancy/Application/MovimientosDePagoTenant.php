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
use Illuminate\Support\Str;

/**
 * Movimientos de dinero del negocio en un rango de fechas (en su zona horaria), con
 * quién hizo cada uno: cobros (en caja, en línea, pagos automáticos), devoluciones,
 * ventas de mostrador y cancelaciones de compras. Sirve de corte de caja: totales
 * por método y por persona del equipo.
 *
 * Quién: el usuario que registró el cobro, hizo la devolución, vendió o canceló; si
 * no hubo uno, lo dice ("En línea", "Pago automático", "Sistema").
 *
 * @phpstan-type Movimiento array{fecha: string, tipo: string, monto_minor: int, moneda: string, metodo: string|null, persona: string|null, concepto: string, quien: string, quien_id: string|null, referencia: string, detalle: string|null}
 */
class MovimientosDePagoTenant
{
    private const LIMITE = 2000;

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    /**
     * @param  string|null  $usuarioUlid  solo lo que hizo esa persona del equipo
     * @param  string|null  $tipo  cobro | devolucion | venta | cancelacion
     * @return array{movimientos: list<Movimiento>, totales: array{cobrado_minor: int, devuelto_minor: int, neto_minor: int, por_metodo: array<string, int>, por_usuario: list<array{quien: string, cobrado_minor: int, devuelto_minor: int}>}}
     */
    public function listar(string $desde, string $hasta, ?string $usuarioUlid = null, ?string $tipo = null): array
    {
        $zona = (string) ($this->gestor->actual()?->zona_horaria ?: 'America/Mexico_City');
        $inicio = CarbonImmutable::parse($desde, $zona)->startOfDay()->utc();
        $fin = CarbonImmutable::parse($hasta, $zona)->endOfDay()->utc();

        $usuarioId = null;
        if ($usuarioUlid !== null && $usuarioUlid !== '') {
            $usuarioId = (int) (Usuario::withTrashed()->where('ulid', $usuarioUlid)->value('id') ?? 0);
        }

        $movimientos = [];
        if ($tipo === null || $tipo === '' || $tipo === 'cobro') {
            $movimientos = [...$movimientos, ...$this->cobros($inicio, $fin, $usuarioId)];
        }
        if ($tipo === null || $tipo === '' || $tipo === 'devolucion') {
            $movimientos = [...$movimientos, ...$this->devoluciones($inicio, $fin, $usuarioId)];
        }
        if ($tipo === null || $tipo === '' || $tipo === 'venta') {
            $movimientos = [...$movimientos, ...$this->ventas($inicio, $fin, $usuarioId)];
        }
        if ($tipo === null || $tipo === '' || $tipo === 'cancelacion') {
            $movimientos = [...$movimientos, ...$this->cancelaciones($inicio, $fin, $usuarioId)];
        }

        usort($movimientos, static fn (array $a, array $b): int => strcmp($b['fecha'], $a['fecha']));

        return ['movimientos' => $movimientos, 'totales' => $this->totales($movimientos)];
    }

    /**
     * @return list<Movimiento>
     */
    private function cobros(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $usuarioId): array
    {
        $pagos = PagoTenant::query()
            ->whereIn('estado', [EstadoPago::Aprobado->value, EstadoPago::ParcialmenteReembolsado->value, EstadoPago::Reembolsado->value])
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($usuarioId !== null, fn ($q) => $q->where('registrado_por', $usuarioId))
            ->with(['orden.persona', 'orden.lineas.producto'])
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();
        $nombres = $this->nombres($pagos->pluck('registrado_por')->all());

        return $pagos->map(function (PagoTenant $pago) use ($nombres): array {
            $registro = $pago->registrado_por !== null ? $nombres[(int) $pago->registrado_por] ?? null : null;

            return [
                'fecha' => (string) $pago->created_at?->toIso8601String(),
                'tipo' => 'cobro',
                'monto_minor' => $pago->monto_minor,
                'moneda' => $pago->moneda,
                'metodo' => $pago->metodo->value ?? $pago->proveedor,
                'persona' => $pago->orden?->persona?->nombreCompleto(),
                'concepto' => self::concepto($pago->orden),
                'quien' => $registro['nombre'] ?? ($pago->domiciliacion_id !== null ? 'Pago automático' : 'En línea'),
                'quien_id' => $registro['ulid'] ?? null,
                'referencia' => (string) $pago->ulid,
                'detalle' => $pago->proveedor,
            ];
        })->values()->all();
    }

    /**
     * @return list<Movimiento>
     */
    private function devoluciones(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $usuarioId): array
    {
        $reembolsos = ReembolsoTenant::query()
            ->where('estado', EstadoReembolso::Aprobado->value)
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($usuarioId !== null, fn ($q) => $q->where('actor_id', $usuarioId))
            ->with(['pago.orden.persona', 'pago.orden.lineas.producto'])
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();
        $nombres = $this->nombres($reembolsos->pluck('actor_id')->all());

        return $reembolsos->map(function (ReembolsoTenant $r) use ($nombres): array {
            $actor = $r->actor_id !== null ? $nombres[(int) $r->actor_id] ?? null : null;

            return [
                'fecha' => (string) $r->created_at?->toIso8601String(),
                'tipo' => 'devolucion',
                'monto_minor' => -$r->monto_minor,
                'moneda' => $r->moneda,
                'metodo' => $r->pago->metodo->value ?? $r->pago->proveedor ?? null,
                'persona' => $r->pago?->orden?->persona?->nombreCompleto(),
                'concepto' => self::concepto($r->pago?->orden),
                'quien' => $actor['nombre'] ?? ($r->actor_nombre ?: 'Sistema'),
                'quien_id' => $actor['ulid'] ?? null,
                'referencia' => (string) $r->ulid,
                'detalle' => $r->motivo,
            ];
        })->values()->all();
    }

    /**
     * @return list<Movimiento>
     */
    private function ventas(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $usuarioId): array
    {
        $ventas = VentaPosTenant::query()
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($usuarioId !== null, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->with('lineas.articulo')
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();
        $nombres = $this->nombres($ventas->pluck('usuario_id')->all());

        return $ventas->map(function (VentaPosTenant $v) use ($nombres): array {
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
                'detalle' => null,
            ];
        })->values()->all();
    }

    /**
     * Compras canceladas (no mueven dinero; se listan para saber quién y cuándo).
     *
     * @return list<Movimiento>
     */
    private function cancelaciones(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $usuarioId): array
    {
        $ordenes = OrdenTenant::query()
            ->where('estado', EstadoOrden::Cancelada->value)
            ->whereBetween('cancelada_en', [$inicio, $fin])
            ->when($usuarioId !== null, fn ($q) => $q->where('cancelada_por', $usuarioId))
            ->with(['persona', 'lineas.producto'])
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();
        $nombres = $this->nombres($ordenes->pluck('cancelada_por')->all());

        return $ordenes->map(function (OrdenTenant $o) use ($nombres): array {
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
                'detalle' => 'Total '.number_format($o->total_minor / 100, 2, '.', ','),
            ];
        })->values()->all();
    }

    /**
     * @param  list<Movimiento>  $movimientos
     * @return array{cobrado_minor: int, devuelto_minor: int, neto_minor: int, por_metodo: array<string, int>, por_usuario: list<array{quien: string, cobrado_minor: int, devuelto_minor: int}>}
     */
    private function totales(array $movimientos): array
    {
        $cobrado = 0;
        $devuelto = 0;
        $porMetodo = [];
        $porUsuario = [];
        foreach ($movimientos as $m) {
            if ($m['tipo'] === 'cancelacion') {
                continue;
            }
            $metodo = MetodoPago::tryFrom((string) $m['metodo'])?->etiqueta() ?? ucfirst((string) ($m['metodo'] ?? 'Otro'));
            $porMetodo[$metodo] = ($porMetodo[$metodo] ?? 0) + $m['monto_minor'];
            $porUsuario[$m['quien']] ??= ['quien' => $m['quien'], 'cobrado_minor' => 0, 'devuelto_minor' => 0];
            if ($m['monto_minor'] >= 0) {
                $cobrado += $m['monto_minor'];
                $porUsuario[$m['quien']]['cobrado_minor'] += $m['monto_minor'];
            } else {
                $devuelto += -$m['monto_minor'];
                $porUsuario[$m['quien']]['devuelto_minor'] += -$m['monto_minor'];
            }
        }

        return [
            'cobrado_minor' => $cobrado,
            'devuelto_minor' => $devuelto,
            'neto_minor' => $cobrado - $devuelto,
            'por_metodo' => $porMetodo,
            'por_usuario' => array_values($porUsuario),
        ];
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
