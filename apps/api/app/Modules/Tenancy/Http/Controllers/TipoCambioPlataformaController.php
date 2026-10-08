<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\TiposDeCambio;
use App\Modules\Tenancy\Models\TipoCambio;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Tipo de cambio con que se cobra en pesos la renta en dólares (superadmin, ADR
 * 0107): el FIX del Banco de México si hay `BANXICO_TOKEN`; si no, el que se captura
 * aquí (también sirve para corregir el de un día).
 */
class TipoCambioPlataformaController
{
    public function __construct(private readonly TiposDeCambio $tipos) {}

    public function index(): JsonResponse
    {
        $ultimo = $this->tipos->ultimo();

        return response()->json(['data' => [
            'banxico_configurado' => $this->tipos->banxicoConfigurado(),
            'ultimo' => $ultimo === null ? null : [
                'valor' => TiposDeCambio::formatear($ultimo['diezmilesimas']), 'fecha' => $ultimo['fecha'], 'fuente' => $ultimo['fuente'],
            ],
            'historial' => TipoCambio::query()->where('de', 'USD')->where('a', 'MXN')
                ->orderByDesc('fecha')->limit(15)->get()
                ->map(static fn (TipoCambio $t): array => [
                    'valor' => TiposDeCambio::formatear($t->diezmilesimas), 'fecha' => $t->fecha->toDateString(), 'fuente' => $t->fuente,
                ])->all(),
        ]]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'valor' => ['required', 'string', 'max:12'],
            'fecha' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $diezmilesimas = TiposDeCambio::aDiezmilesimas((string) $validado['valor']);
        // Un peso por dólar o cien: fuera de eso es un error de captura.
        if ($diezmilesimas === null || $diezmilesimas < 10000 || $diezmilesimas > 1000000) {
            throw ValidationException::withMessages(['valor' => ['Escribe los pesos por dólar, p. ej. 17.2345.']]);
        }
        $fecha = isset($validado['fecha'])
            ? CarbonImmutable::parse((string) $validado['fecha'])
            : CarbonImmutable::now('America/Mexico_City');

        $this->tipos->registrarManual($diezmilesimas, $fecha);
        Log::info('plataforma.tipo_cambio', ['fecha' => $fecha->toDateString(), 'valor' => TiposDeCambio::formatear($diezmilesimas)]);

        return $this->index();
    }
}
