<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Facturación propia de cada negocio (ADR 0212):
|
| - En sus datos fiscales, cómo va su alta en FacturAPI: si ya puede timbrar, lo que
|   le falta (p. ej. firmar la carta manifiesto) y el último error de FacturAPI.
| - En cada producto, servicio y artículo del mostrador, el IVA con que se factura (`general`, `exento` o
|   `cero`; los servicios médicos suelen ser exentos) y, si hace falta, su clave de
|   producto o servicio del SAT. Sin dato, el IVA del negocio y la clave de siempre.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('datos_fiscales', function (Blueprint $t): void {
            $t->boolean('facturapi_lista')->default(false);
            $t->json('facturapi_pendientes')->nullable();
            $t->string('facturapi_error', 500)->nullable();
        });
        foreach (['productos_comerciales', 'ofertas', 'articulos'] as $tabla) {
            Schema::connection('tenant')->table($tabla, function (Blueprint $t): void {
                $t->string('iva', 10)->default('general');
                $t->string('clave_sat', 8)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['productos_comerciales', 'ofertas', 'articulos'] as $tabla) {
            Schema::connection('tenant')->table($tabla, function (Blueprint $t): void {
                $t->dropColumn(['iva', 'clave_sat']);
            });
        }
        Schema::connection('tenant')->table('datos_fiscales', function (Blueprint $t): void {
            $t->dropColumn(['facturapi_lista', 'facturapi_pendientes', 'facturapi_error']);
        });
    }
};
