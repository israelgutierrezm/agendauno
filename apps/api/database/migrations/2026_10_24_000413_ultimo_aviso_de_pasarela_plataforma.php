<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cuándo llegó el último aviso verificado de cada pasarela de la plataforma (Stripe,
| Mercado Pago u OpenPay): la verificación de producción comprueba así que la
| pasarela habilitada sí está avisando de los pagos de la renta, no solo que tiene
| llaves.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_pasarela_plataforma', function (Blueprint $t): void {
            $t->timestamp('ultimo_aviso_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_pasarela_plataforma', function (Blueprint $t): void {
            $t->dropColumn('ultimo_aviso_en');
        });
    }
};
