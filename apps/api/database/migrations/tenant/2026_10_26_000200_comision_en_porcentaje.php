<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Comisión en porcentaje (ADR 0219): un profesional de citas gana un porcentaje de lo
| que se cobra por sus citas. Va en puntos base (1000 = 10 %) en el esquema de pago y
| en cada versión de su tarifa.
*/
return new class extends Migration
{
    public function up(): void
    {
        foreach (['esquemas_pago', 'esquemas_pago_versiones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $t): void {
                $t->unsignedInteger('porcentaje_bp')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['esquemas_pago', 'esquemas_pago_versiones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $t): void {
                $t->dropColumn('porcentaje_bp');
            });
        }
    }
};
