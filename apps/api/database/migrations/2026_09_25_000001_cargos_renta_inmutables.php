<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cargo de renta del SaaS explicable e inmutable (fase 1, punto 1.6): cada cargo guarda
| con qué se calculó (la medición congelada del mes y la versión de la regla que
| decide quién cuenta, además de la tarifa que ya guardaba) y cuándo se emitió; una vez
| emitido no se recalcula.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargos_renta', function (Blueprint $tabla): void {
            $tabla->foreignId('medicion_id')->nullable()->constrained('mediciones_uso')->nullOnDelete();
            $tabla->string('regla_version', 40)->nullable();
            $tabla->timestamp('emitido_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cargos_renta', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('medicion_id');
            $tabla->dropColumn(['regla_version', 'emitido_en']);
        });
    }
};
