<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\TimbresAgotados;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\MovimientoTimbreTenant;
use App\Modules\Tenancy\Models\SaldoTimbresTenant;
use Illuminate\Support\Facades\DB;

/**
 * Los timbres del negocio para facturar a sus clientes (ADR 0107), en su propia base:
 * saldo con movimientos. Se compran en paquetes (con Stripe, en pesos) y cada factura
 * timbrada gasta uno; sin saldo no se timbra. Es el control de cuántas facturas
 * emite: AgendaUno paga a FacturAPI su membresía y cada timbre.
 *
 * El saldo se mueve con su fila bloqueada: dos facturas a la vez no gastan el mismo
 * timbre, y una compra se suma una sola vez.
 */
class TimbresTenant
{
    public function __construct(private readonly ParametrosTenant $parametros) {}

    public function disponibles(): int
    {
        return (int) (SaldoTimbresTenant::query()->value('disponibles') ?? 0);
    }

    /** Precio de un timbre en centavos de peso, sin IVA. */
    public function precioTimbreMinor(): int
    {
        return $this->parametros->entero('timbres.precio_centavos');
    }

    /**
     * Los paquetes a la venta (los fija el superadmin) con su precio (sin IVA, en pesos).
     *
     * @return list<array{cantidad: int, precio_minor: int}>
     */
    public function paquetes(): array
    {
        $precio = $this->precioTimbreMinor();

        return array_map(static fn (int $cantidad): array => ['cantidad' => $cantidad, 'precio_minor' => $cantidad * $precio], ConfiguracionPlataforma::paquetesTimbres());
    }

    /**
     * Suma los timbres de una compra pagada (una sola vez por referencia).
     */
    public function acreditar(int $cantidad, string $referencia, string $detalle): void
    {
        DB::connection('tenant')->transaction(function () use ($cantidad, $referencia, $detalle): void {
            $saldo = $this->bloquear();
            if (MovimientoTimbreTenant::query()->where('tipo', 'compra')->where('referencia', $referencia)->exists()) {
                return;
            }
            $this->mover($saldo, 'compra', $cantidad, $referencia, $detalle);
        });
    }

    /**
     * Bloquea el saldo para timbrar: sin timbres, no se timbra. Se llama dentro de la
     * transacción del timbrado; si la factura sale, se gasta con {@see gastar()}.
     *
     * @throws TimbresAgotados
     */
    public function apartarParaTimbrar(): SaldoTimbresTenant
    {
        $saldo = $this->bloquear();
        if ($saldo->disponibles < 1) {
            throw new TimbresAgotados('Ya no tienes timbres para facturar. Compra un paquete en Mi suscripción.');
        }

        return $saldo;
    }

    /** Gasta el timbre de una factura ya timbrada. */
    public function gastar(SaldoTimbresTenant $saldo, string $factura): void
    {
        $this->mover($saldo, 'consumo', -1, $factura, null);
    }

    /**
     * Los últimos movimientos, para mostrarlos.
     *
     * @return list<array{id: string, tipo: string, cantidad: int, saldo_despues: int, detalle: string|null, fecha: string|null}>
     */
    public function movimientos(int $limite = 20): array
    {
        return MovimientoTimbreTenant::query()->orderByDesc('id')->limit($limite)->get()
            ->map(static fn (MovimientoTimbreTenant $m): array => [
                'id' => (string) $m->ulid,
                'tipo' => $m->tipo,
                'cantidad' => $m->cantidad,
                'saldo_despues' => $m->saldo_despues,
                'detalle' => $m->detalle,
                'fecha' => $m->created_at?->toIso8601String(),
            ])->all();
    }

    private function bloquear(): SaldoTimbresTenant
    {
        $saldo = SaldoTimbresTenant::query()->orderBy('id')->lockForUpdate()->first();

        return $saldo ?? SaldoTimbresTenant::query()->create(['disponibles' => 0]);
    }

    private function mover(SaldoTimbresTenant $saldo, string $tipo, int $cantidad, ?string $referencia, ?string $detalle): void
    {
        $nuevo = $saldo->disponibles + $cantidad;
        MovimientoTimbreTenant::query()->create([
            'tipo' => $tipo, 'cantidad' => $cantidad, 'saldo_despues' => $nuevo, 'referencia' => $referencia, 'detalle' => $detalle,
        ]);
        $saldo->update(['disponibles' => $nuevo]);
    }
}
