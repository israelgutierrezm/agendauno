<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Medicion\Atribucion;
use App\Modules\Tenancy\Medicion\IdentificadoresNavegador;
use App\Modules\Tenancy\Models\ContextoMedicionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Guarda con la solicitud, la compra o la cita de dónde llegó quien la hizo (ADR 0142),
 * con las ventanas de atribución del negocio, y sus preferencias de medición con los
 * identificadores de su navegador que permitió (ADR 0143). Nunca impide el registro: si
 * algo falla al guardarla, el registro sigue «sin atribución».
 */
class AtribucionTenant
{
    public function __construct(private readonly ParametrosTenant $parametros) {}

    public function registrar(mixed $entrada, ?PersonaTenant $persona = null, mixed $medicion = null, ?string $ip = null, ?string $agente = null): ?ContextoMedicionTenant
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

            return ContextoMedicionTenant::query()->create([
                'persona_id' => $persona?->getKey(),
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
