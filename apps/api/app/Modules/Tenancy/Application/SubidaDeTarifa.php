<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Mail\CorreoSubidaDeTarifa;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;

/**
 * Lo que los términos prometen al subir precios (ADR 0234): una subida rige a 30 días o
 * más y se avisa por correo a los dueños afectados; bajar puede ser inmediato.
 */
class SubidaDeTarifa
{
    public const DIAS_DE_AVISO = 30;

    /**
     * ¿La nueva definición cuesta más que la vigente en algún caso? Lo que no se puede
     * comparar (otra moneda) cuenta como subida; las definiciones anteriores a bandas o
     * niveles ya no rigen a nadie.
     *
     * @param  array<string, mixed>  $vigente
     * @param  array<string, mixed>  $nueva
     */
    public function esSubida(array $vigente, array $nueva): bool
    {
        if (($vigente['moneda'] ?? 'USD') !== ($nueva['moneda'] ?? 'USD')) {
            return true;
        }
        foreach (['iva_porcentaje', 'iva_porcentaje_extranjero', 'meses_anual'] as $campo) {
            if ((int) ($nueva[$campo] ?? 0) > (int) ($vigente[$campo] ?? 0) && isset($vigente[$campo])) {
                return true;
            }
        }

        return $this->subeBanda($vigente['bandas'] ?? null, $nueva['bandas'] ?? null)
            || $this->subeNivel($vigente['niveles'] ?? null, $nueva['niveles'] ?? null);
    }

    /** Lo más pronto que puede regir una subida publicada hoy. */
    public function primerDiaPermitido(): CarbonInterface
    {
        return now()->startOfDay()->addDays(self::DIAS_DE_AVISO);
    }

    /** Avisa a los dueños de los negocios de esa modalidad (el correo va en la cola). */
    public function avisar(ModalidadServicio $modalidad, CarbonInterface $vigenteDesde): void
    {
        Estudio::query()
            ->where('modalidad', $modalidad->value)
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value, EstadoEstudio::Suspended->value])
            ->select(['id', 'nombre', 'contacto_email', 'modalidad'])
            ->chunkById(200, function ($estudios) use ($vigenteDesde): void {
                foreach ($estudios as $estudio) {
                    /** @var Estudio $estudio */
                    $correo = trim((string) $estudio->contacto_email);
                    if ($correo !== '') {
                        Mail::to($correo)->queue(new CorreoSubidaDeTarifa((string) $estudio->nombre, $vigenteDesde->toDateString(), $estudio->producto()));
                    }
                }
            });
    }

    /**
     * Por alumnos activos: se compara en cada escalón de las dos tablas.
     */
    private function subeBanda(mixed $vigente, mixed $nueva): bool
    {
        if (! is_array($vigente) || ! is_array($nueva) || $vigente === [] || $nueva === []) {
            return false;
        }
        $puntos = [];
        foreach ([...$vigente, ...$nueva] as $banda) {
            $hasta = $banda['hasta'] ?? null;
            if ($hasta !== null) {
                $puntos[] = (int) $hasta;
                $puntos[] = (int) $hasta + 1;
            }
        }
        foreach (array_unique($puntos) as $alumnos) {
            if ($this->montoDeBanda($nueva, $alumnos) > $this->montoDeBanda($vigente, $alumnos)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $bandas
     */
    private function montoDeBanda(array $bandas, int $alumnos): int
    {
        foreach ($bandas as $banda) {
            if (($banda['hasta'] ?? null) === null || $alumnos <= (int) $banda['hasta']) {
                return (int) $banda['monto_minor'];
            }
        }

        return (int) ($bandas[array_key_last($bandas)]['monto_minor'] ?? 0);
    }

    /**
     * Por plan y profesionales: se compara lo que existe en las dos tablas.
     */
    private function subeNivel(mixed $vigente, mixed $nueva): bool
    {
        if (! is_array($vigente) || ! is_array($nueva)) {
            return false;
        }
        foreach ($nueva as $nivel => $precios) {
            foreach ((array) $precios as $profesionales => $monto) {
                $antes = $vigente[$nivel][$profesionales] ?? null;
                if ($antes !== null && (int) $monto > (int) $antes) {
                    return true;
                }
            }
        }

        return false;
    }
}
