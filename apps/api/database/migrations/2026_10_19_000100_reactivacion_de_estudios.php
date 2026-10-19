<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cuándo volvió a abrir un negocio suspendido (ADR 0166): lo que pasó mientras estuvo
| cerrado no se le cobra a sus clientes al reabrir (faltas automáticas de clases que
| nadie pudo registrar, mora de socios que no pudieron pagar).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->timestamp('reactivado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn('reactivado_en');
        });
    }
};
