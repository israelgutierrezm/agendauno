<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Baja de un negocio (ADR 0213): la pide su dueño o la plataforma. Queda `cancelled`
| desde `dado_de_baja_en`; `baja_por` dice quién (`dueno` o `plataforma`) y
| `baja_motivo` por qué. Sus datos se borran el día `datos_borrar_en` (hasta entonces
| se puede revertir) y `datos_borrados_en` dice cuándo se borraron. El registro, sus
| cargos y sus facturas de la renta se quedan: la ley obliga a guardarlos.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $t): void {
            $t->timestamp('dado_de_baja_en')->nullable();
            $t->string('baja_por', 20)->nullable();
            $t->string('baja_motivo', 500)->nullable();
            $t->date('datos_borrar_en')->nullable();
            $t->timestamp('datos_borrados_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $t): void {
            $t->dropColumn(['dado_de_baja_en', 'baja_por', 'baja_motivo', 'datos_borrar_en', 'datos_borrados_en']);
        });
    }
};
