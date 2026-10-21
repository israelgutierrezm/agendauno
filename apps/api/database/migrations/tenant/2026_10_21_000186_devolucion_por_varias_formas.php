<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Devolución de una venta con varias formas de pago (ADR 0178, ajuste): si por la forma
| elegida no alcanza, el resto sale por las otras formas de la venta. Aquí queda cómo se
| repartió ([{metodo, monto_minor}]); `metodo` es «mixto» cuando salió por más de una.
| Las anteriores, sin reparto, salieron completas por su `metodo`.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('devoluciones_venta', function (Blueprint $t): void {
            $t->json('partes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('devoluciones_venta', function (Blueprint $t): void {
            $t->dropColumn('partes');
        });
    }
};
