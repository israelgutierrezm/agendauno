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
 * ventas de mostrador y cancelaciones, con totales del rango completo por moneda. Sin
 * fechas, el día de hoy (en la zona del negocio). La lista trae a lo más `limite`
 * filas por tipo (avisa si se cortó); `?formato=csv` descarga TODAS con los mismos
 * totales.
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
            'limite' => ['nullable', 'integer', 'min:1', 'max:'.MovimientosDePagoTenant::LIMITE],
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

        if (($validado['formato'] ?? 'json') === 'csv') {
            $todo = $this->movimientos->listar($desde, $hasta, $validado['usuario'] ?? null, $validado['tipo'] ?? null, null);

            return $this->csv($todo['movimientos'], $todo['totales'], $desde, $hasta, $zona);
        }

        $limite = (int) ($validado['limite'] ?? MovimientosDePagoTenant::LIMITE);
        $resultado = $this->movimientos->listar($desde, $hasta, $validado['usuario'] ?? null, $validado['tipo'] ?? null, $limite);

        return response()->json([
            'data' => $resultado['movimientos'],
            // La moneda principal (compatibilidad) y todas por separado: nunca se suman.
            'totales' => $resultado['totales'][0] ?? [
                'moneda' => 'MXN', 'cobrado_minor' => 0, 'devuelto_minor' => 0, 'neto_minor' => 0,
                'por_cobrar_minor' => 0, 'por_metodo' => [], 'por_usuario' => [],
            ],
            'totales_por_moneda' => $resultado['totales'],
            'meta' => ['desde' => $desde, 'hasta' => $hasta, 'truncado' => $resultado['truncado'], 'limite' => $limite],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $movimientos
     * @param  list<array{moneda: string, cobrado_minor: int, devuelto_minor: int, neto_minor: int, por_cobrar_minor: int}>  $totales
     */
    private function csv(array $movimientos, array $totales, string $desde, string $hasta, string $zona): Response
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

        // Los mismos totales del reporte, por moneda.
        $lineas[] = '';
        foreach ($totales as $t) {
            foreach (['Total cobrado' => $t['cobrado_minor'], 'Total devuelto' => $t['devuelto_minor'], 'Neto' => $t['neto_minor'], 'Por cobrar' => $t['por_cobrar_minor']] as $concepto => $minor) {
                $lineas[] = $concepto.','.number_format($minor / 100, 2, '.', '').','.$t['moneda'];
            }
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
