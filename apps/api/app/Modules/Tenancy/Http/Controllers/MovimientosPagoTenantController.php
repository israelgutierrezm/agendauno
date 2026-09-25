<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\MovimientosDePagoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Movimientos de dinero por fecha y por quién (corte de caja): cobros, devoluciones,
 * ventas de mostrador y cancelaciones, con totales. Sin fechas, el día de hoy (en la
 * zona del negocio). `?formato=csv` lo descarga.
 */
class MovimientosPagoTenantController
{
    private const MAX_DIAS = 366;

    private const TIPOS = ['cobro' => 'Cobro', 'devolucion' => 'Devolución', 'venta' => 'Venta', 'cancelacion' => 'Cancelación'];

    public function __construct(
        private readonly MovimientosDePagoTenant $movimientos,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $validado = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'usuario' => ['nullable', 'string', 'max:40'],
            'tipo' => ['nullable', Rule::in(array_keys(self::TIPOS))],
            'formato' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        $zona = $this->zona();
        $desde = (string) ($validado['desde'] ?? CarbonImmutable::now($zona)->toDateString());
        $hasta = (string) ($validado['hasta'] ?? $desde);
        if ($hasta < $desde) {
            throw ValidationException::withMessages(['hasta' => ['La fecha final debe ser igual o posterior a la inicial.']]);
        }
        if (CarbonImmutable::parse($desde)->diffInDays(CarbonImmutable::parse($hasta)) > self::MAX_DIAS) {
            throw ValidationException::withMessages(['hasta' => ['Consulta a lo más un año a la vez.']]);
        }

        $resultado = $this->movimientos->listar($desde, $hasta, $validado['usuario'] ?? null, $validado['tipo'] ?? null);

        if (($validado['formato'] ?? 'json') === 'csv') {
            return $this->csv($resultado['movimientos'], $desde, $hasta, $zona);
        }

        return response()->json([
            'data' => $resultado['movimientos'],
            'totales' => $resultado['totales'],
            'meta' => ['desde' => $desde, 'hasta' => $hasta],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $movimientos
     */
    private function csv(array $movimientos, string $desde, string $hasta, string $zona): Response
    {
        $lineas = ['Fecha,Tipo,Monto,Moneda,Método,Persona,Concepto,Quién,Referencia,Detalle'];
        foreach ($movimientos as $m) {
            $fecha = $m['fecha'] !== '' ? CarbonImmutable::parse((string) $m['fecha'])->setTimezone($zona)->format('Y-m-d H:i') : '';
            $lineas[] = implode(',', array_map(fn (string $v): string => $this->escapar($v), [
                $fecha,
                self::TIPOS[(string) $m['tipo']] ?? (string) $m['tipo'],
                number_format(((int) $m['monto_minor']) / 100, 2, '.', ''),
                (string) $m['moneda'],
                (string) ($m['metodo'] ?? ''),
                (string) ($m['persona'] ?? ''),
                (string) $m['concepto'],
                (string) $m['quien'],
                (string) $m['referencia'],
                (string) ($m['detalle'] ?? ''),
            ]));
        }

        return response("\u{FEFF}".implode("\n", $lineas)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"movimientos-{$desde}-a-{$hasta}.csv\"",
        ]);
    }

    private function escapar(string $valor): string
    {
        // Evita que una hoja de cálculo interprete fórmulas.
        if ($valor !== '' && in_array($valor[0], ['=', '+', '-', '@'], true) && ! is_numeric($valor)) {
            $valor = "'".$valor;
        }

        return str_contains($valor, ',') || str_contains($valor, '"') || str_contains($valor, "\n")
            ? '"'.str_replace('"', '""', $valor).'"'
            : $valor;
    }

    private function zona(): string
    {
        return (string) ($this->gestor->actual()?->zona_horaria ?: 'America/Mexico_City');
    }
}
