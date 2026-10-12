<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Pago automático seguro (ADR 0134):
| - `domiciliaciones`: la cancelación de una suscripción se confirma con la pasarela
|   (estado `cancelando`, cuándo se pidió, intentos, el siguiente y el último error) y
|   lo que el cliente autorizó (`monto_minor`, `moneda`: su precio contratado).
| - `incidencias_cobro`: de qué domiciliación es y la referencia del cobro en la
|   pasarela (una cancelación sin confirmar o un cobro que no se esperaba no se abren
|   dos veces).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('domiciliaciones', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('monto_minor')->nullable();
            $tabla->char('moneda', 3)->nullable();
            $tabla->timestamp('cancelacion_solicitada_en')->nullable();
            $tabla->unsignedSmallInteger('cancelacion_intentos')->default(0);
            $tabla->timestamp('cancelacion_proximo_en')->nullable();
            $tabla->string('cancelacion_error', 255)->nullable();

            $tabla->index(['estado', 'cancelacion_proximo_en']);
        });

        Schema::connection('tenant')->table('incidencias_cobro', function (Blueprint $tabla): void {
            $tabla->foreignId('domiciliacion_id')->nullable()->constrained('domiciliaciones')->nullOnDelete();
            $tabla->string('referencia_externa', 120)->nullable();

            $tabla->index(['tipo', 'referencia_externa']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('incidencias_cobro', function (Blueprint $tabla): void {
            $tabla->dropIndex(['tipo', 'referencia_externa']);
            $tabla->dropConstrainedForeignId('domiciliacion_id');
            $tabla->dropColumn('referencia_externa');
        });

        Schema::connection('tenant')->table('domiciliaciones', function (Blueprint $tabla): void {
            $tabla->dropIndex(['estado', 'cancelacion_proximo_en']);
            $tabla->dropColumn([
                'monto_minor', 'moneda', 'cancelacion_solicitada_en', 'cancelacion_intentos',
                'cancelacion_proximo_en', 'cancelacion_error',
            ]);
        });
    }
};
