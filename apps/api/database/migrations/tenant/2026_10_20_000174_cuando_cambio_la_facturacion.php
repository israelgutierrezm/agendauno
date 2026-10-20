<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Desde cuándo una persona está archivada o no es facturable: la medición de alumnos
| activos del mes los sigue contando si el cambio fue dentro del mes (sí usaron el
| servicio), como ya se hace con las bajas.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->timestamp('archivado_en')->nullable();
            $tabla->timestamp('no_facturable_desde')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->dropColumn(['archivado_en', 'no_facturable_desde']);
        });
    }
};
