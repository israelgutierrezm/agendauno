<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CalcularRentaSaas;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\MonedaDeCobroSaas;
use App\Modules\Tenancy\Application\TimbresTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\TarifaSaas;
use Illuminate\Http\JsonResponse;

/**
 * Precios públicos de la suscripción (sin sesión, ADR 0107): las tarifas vigentes que
 * publica el superadmin, el contacto de ventas para cotizar y los paquetes de
 * timbres. La landing los muestra tal cual; nada de precios fijos en la web.
 */
class PreciosPublicosController
{
    public function __invoke(TimbresTenant $timbres): JsonResponse
    {
        $clases = TarifaSaas::vigente(ModalidadServicio::Clases)->definicion ?? [];
        $citas = TarifaSaas::vigente(ModalidadServicio::Citas)->definicion ?? [];
        $porNiveles = is_array($citas['niveles'] ?? null);

        return response()->json(['data' => [
            'clases' => [
                'moneda' => MonedaDeCobroSaas::deTarifa($clases),
                'dias_prueba' => (int) ($clases['dias_prueba'] ?? 30),
                'bandas' => is_array($clases['bandas'] ?? null) ? array_values($clases['bandas']) : [],
            ],
            'citas' => [
                'moneda' => MonedaDeCobroSaas::deTarifa($citas),
                'dias_prueba' => (int) ($citas['dias_prueba'] ?? 30),
                'meses_anual' => CalcularRentaSaas::mesesAnual($citas),
                'niveles' => $porNiveles ? $citas['niveles'] : null,
                'funciones' => $porNiveles ? FuncionesPlan::mapa($citas) : null,
            ],
            'ventas' => [
                'correo' => ConfiguracionPlataforma::ventasCorreo(),
                'whatsapp' => ConfiguracionPlataforma::ventasWhatsApp(),
            ],
            'timbres' => [
                'moneda' => 'MXN',
                'precio_minor' => $timbres->precioTimbreMinor(),
                'paquetes' => ConfiguracionPlataforma::paquetesTimbres(),
            ],
        ]]);
    }
}
