<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CalcularRentaSaas;
use App\Modules\Tenancy\Application\ComplementosRentaSaas;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\MonedaDeCobroSaas;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\TimbresTenant;
use App\Modules\Tenancy\Application\TiposDeCambio;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\TarifaSaas;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Http\JsonResponse;

/**
 * Precios públicos de la suscripción (sin sesión, ADR 0107): las tarifas vigentes que
 * publica el superadmin, el contacto de ventas para cotizar, los paquetes de
 * timbres y qué producto recibe registros (ADR 0108: TurnoUno abre hasta su
 * lanzamiento; mientras, su landing junta interesados). La landing los muestra tal
 * cual; nada de precios fijos en la web.
 */
class PreciosPublicosController
{
    public function __invoke(TimbresTenant $timbres, ParametrosTenant $parametros, TiposDeCambio $tipos): JsonResponse
    {
        $tipo = $tipos->ultimo();

        $tarifaClases = TarifaSaas::vigente(ModalidadServicio::Clases);
        $tarifaCitas = TarifaSaas::vigente(ModalidadServicio::Citas);
        $clases = $tarifaClases->definicion ?? [];
        $citas = $tarifaCitas->definicion ?? [];
        $porNiveles = is_array($citas['niveles'] ?? null);

        return response()->json(['data' => [
            'clases' => [
                'moneda' => MonedaDeCobroSaas::deTarifa($clases),
                // Desde cuándo rige: la landing y la contratación dicen la misma.
                'vigente_desde' => $tarifaClases?->vigente_desde?->toDateString(),
                'iva_porcentaje' => (int) ($clases['iva_porcentaje'] ?? 16),
                'dias_prueba' => (int) ($clases['dias_prueba'] ?? 30),
                'bandas' => is_array($clases['bandas'] ?? null) ? array_values($clases['bandas']) : [],
            ],
            'citas' => [
                'moneda' => MonedaDeCobroSaas::deTarifa($citas),
                'vigente_desde' => $tarifaCitas?->vigente_desde?->toDateString(),
                'iva_porcentaje' => (int) ($citas['iva_porcentaje'] ?? 16),
                'dias_prueba' => (int) ($citas['dias_prueba'] ?? 30),
                'meses_anual' => CalcularRentaSaas::mesesAnual($citas),
                'niveles' => $porNiveles ? $citas['niveles'] : null,
                'funciones' => $porNiveles ? FuncionesPlan::mapa($citas) : null,
            ],
            'ventas' => [
                'correo' => ConfiguracionPlataforma::ventasCorreo(),
                'whatsapp' => ConfiguracionPlataforma::ventasWhatsApp(),
                // Cada landing cotiza con el de su marca (ADR 0108).
                'correo_por_producto' => collect(ProductoComercial::cases())
                    ->mapWithKeys(fn (ProductoComercial $p): array => [$p->value => ConfiguracionPlataforma::ventasCorreo($p)])
                    ->all(),
            ],
            'timbres' => [
                'moneda' => 'MXN',
                'precio_minor' => $timbres->precioTimbreMinor(),
                'paquetes' => ConfiguracionPlataforma::paquetesTimbres(),
            ],
            'registro' => collect(ProductoComercial::cases())
                ->mapWithKeys(fn (ProductoComercial $p): array => [$p->value => $parametros->siNo('registro.abierto_'.$p->value)])
                ->all(),
            // El último FIX conocido, para mostrar en México el equivalente en pesos con
            // IVA (el cargo usa el del día en que se emite).
            'tipo_cambio' => $tipo === null ? null : ['diezmilesimas' => $tipo['diezmilesimas'], 'fecha' => $tipo['fecha']],
            // Lo que se cobra aparte, con precio publicado, y los referidos (ADR 0188).
            'servicios' => app(ComplementosRentaSaas::class)->precios(),
            'referidos' => ['meses_gratis' => 1],
        ]]);
    }
}
