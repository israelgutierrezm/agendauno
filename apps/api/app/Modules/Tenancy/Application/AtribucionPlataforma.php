<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Medicion\Atribucion;
use App\Modules\Tenancy\Medicion\IdentificadoresNavegador;
use App\Modules\Tenancy\Models\ContextoMedicionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Guarda con el negocio recién registrado de dónde llegó quien lo registró (ADR 0142),
 * con las ventanas de atribución de la plataforma, y sus preferencias de medición con
 * los identificadores de su navegador que permitió (ADR 0143). Nunca impide el
 * registro.
 */
class AtribucionPlataforma
{
    public function __construct(private readonly ParametrosTenant $parametros) {}

    public function registrar(Estudio $estudio, mixed $entrada, mixed $medicion = null, ?string $ip = null, ?string $agente = null): ?ContextoMedicionPlataforma
    {
        try {
            $ahora = CarbonImmutable::now();
            $atribucion = Atribucion::desdeEntrada(
                $entrada,
                $ahora,
                $this->parametros->entero('medicion.dias_primer_toque'),
                $this->parametros->entero('medicion.dias_ultimo_toque'),
            );
            $identificadores = IdentificadoresNavegador::desdeEntrada($medicion, $entrada, $ip, $agente, $ahora);
            if ($atribucion === null && $identificadores === null) {
                return null;
            }

            return ContextoMedicionPlataforma::query()->updateOrCreate(['estudio_id' => $estudio->getKey()], [
                'producto' => $estudio->producto()->value,
                ...($atribucion?->acreditada() ?? []),
                'primer_toque' => $atribucion?->primerToque,
                'ultimo_toque' => $atribucion?->ultimoToque,
                ...($identificadores ?? []),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
