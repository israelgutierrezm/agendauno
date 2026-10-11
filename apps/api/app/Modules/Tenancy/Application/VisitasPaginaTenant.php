<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cuántas personas vieron la página del negocio cada día (plan de mejoras C08), para el
 * embudo «sitio → solicitud». Una visita por visitante y día: se reconoce por una huella
 * pasajera (IP y navegador con el día y el negocio, en hash) que vive solo en la caché
 * hasta el día siguiente. En la base solo queda el número del día: nada que identifique
 * a nadie.
 */
class VisitasPaginaTenant
{
    public function __construct(private readonly FechasNegocioTenant $fechas) {}

    public function registrar(Estudio $estudio, ?string $ip, ?string $navegador): void
    {
        $dia = $this->fechas->hoy();
        $huella = hash('sha256', implode('|', [$estudio->slug, $dia, (string) $ip, (string) $navegador]));
        // Solo la primera vez del día cuenta.
        if (! Cache::add("visita-pagina:{$huella}", 1, now()->addDay())) {
            return;
        }

        $sumar = fn (): int => DB::connection('tenant')->table('visitas_pagina')
            ->where('fecha', $dia)
            ->update(['visitas' => DB::raw('visitas + 1'), 'updated_at' => now()]);
        if ($sumar() > 0) {
            return;
        }
        try {
            DB::connection('tenant')->table('visitas_pagina')->insert([
                'fecha' => $dia, 'visitas' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra visita creó el día al mismo tiempo: se suma a ese.
            $sumar();
        }
    }

    /** Las visitas entre dos fechas del negocio (incluidas). */
    public function entre(string $desde, string $hasta): int
    {
        return (int) DB::connection('tenant')->table('visitas_pagina')
            ->whereBetween('fecha', [$desde, $hasta])
            ->sum('visitas');
    }
}
