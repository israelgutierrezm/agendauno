<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Modelo comercial en USD (ADR 0107):
| - `estudios`: el plan de un negocio de citas (nivel, profesionales contratados,
|   mensual o anual, hasta cuándo está cubierto y el cambio que aplica en el siguiente
|   periodo) y la moneda de una cuota fija pactada.
| - `cargos_renta`: un periodo puede tener su cargo y sus ajustes prorrateados (`clave`),
|   lo que cubre (`cubre_desde`/`cubre_hasta`), el importe en la moneda de la tarifa
|   (USD) y el tipo de cambio con que se convirtió a pesos (en diezmilésimas, sin
|   decimales flotantes).
| - `tipos_cambio`: el tipo de cambio de cada día con su fuente (Banxico o manual).
| - Domiciliación: el cliente de Stripe del negocio en la cuenta de la plataforma, su
|   tarjeta guardada (solo marca, últimos 4 y vencimiento) y, en cada cargo, los
|   intentos de cobro automático.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->string('plan_nivel', 20)->nullable();
            $tabla->unsignedSmallInteger('plan_profesionales')->nullable();
            $tabla->string('plan_periodicidad', 10)->default('mensual');
            $tabla->date('plan_cubierto_hasta')->nullable();
            $tabla->json('plan_siguiente')->nullable();
            $tabla->char('cuota_fija_moneda', 3)->default('MXN');
            $tabla->string('stripe_cliente_id', 64)->nullable();
            $tabla->string('domiciliacion_metodo', 64)->nullable();
            $tabla->string('tarjeta_marca', 20)->nullable();
            $tabla->string('tarjeta_ultimos4', 4)->nullable();
            $tabla->string('tarjeta_vence', 7)->nullable();
            $tabla->timestamp('domiciliada_en')->nullable();
        });

        Schema::table('cargos_renta', function (Blueprint $tabla): void {
            $tabla->string('clave', 60)->default('periodo');
            $tabla->string('concepto', 20)->nullable();
            $tabla->date('cubre_desde')->nullable();
            $tabla->date('cubre_hasta')->nullable();
            $tabla->unsignedBigInteger('monto_tarifa_minor')->nullable();
            $tabla->char('moneda_tarifa', 3)->nullable();
            $tabla->unsignedBigInteger('tipo_cambio_diezmilesimas')->nullable();
            $tabla->date('tipo_cambio_fecha')->nullable();
            $tabla->string('tipo_cambio_fuente', 20)->nullable();
            $tabla->unsignedTinyInteger('intentos_automaticos')->default(0);
            $tabla->timestamp('proximo_intento_en')->nullable();
            $tabla->string('error_cobro', 255)->nullable();
        });
        // Primero el índice nuevo (también empieza por el negocio y sirve a su llave
        // foránea); después se quita el anterior.
        Schema::table('cargos_renta', function (Blueprint $tabla): void {
            $tabla->unique(['estudio_id', 'periodo', 'clave']);
        });
        Schema::table('cargos_renta', function (Blueprint $tabla): void {
            $tabla->dropUnique(['estudio_id', 'periodo']);
        });

        Schema::create('tipos_cambio', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->date('fecha');
            $tabla->char('de', 3);
            $tabla->char('a', 3);
            $tabla->unsignedBigInteger('diezmilesimas');
            $tabla->string('fuente', 20);
            $tabla->timestamps();

            $tabla->unique(['fecha', 'de', 'a']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_cambio');
        Schema::table('cargos_renta', function (Blueprint $tabla): void {
            $tabla->unique(['estudio_id', 'periodo']);
        });
        Schema::table('cargos_renta', function (Blueprint $tabla): void {
            $tabla->dropUnique(['estudio_id', 'periodo', 'clave']);
            $tabla->dropColumn([
                'clave', 'concepto', 'cubre_desde', 'cubre_hasta', 'monto_tarifa_minor', 'moneda_tarifa',
                'tipo_cambio_diezmilesimas', 'tipo_cambio_fecha', 'tipo_cambio_fuente',
                'intentos_automaticos', 'proximo_intento_en', 'error_cobro',
            ]);
        });
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn([
                'plan_nivel', 'plan_profesionales', 'plan_periodicidad', 'plan_cubierto_hasta', 'plan_siguiente', 'cuota_fija_moneda',
                'stripe_cliente_id', 'domiciliacion_metodo', 'tarjeta_marca', 'tarjeta_ultimos4', 'tarjeta_vence', 'domiciliada_en',
            ]);
        });
    }
};
