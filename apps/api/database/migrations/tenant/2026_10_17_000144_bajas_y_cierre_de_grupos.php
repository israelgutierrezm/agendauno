<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Grupos y cursos (R25): una baja tiene fecha (desde cuándo deja de venir, y se le
| cancelan sus reservas del grupo desde ahí) y un grupo se cierra (ya no inscribe a
| nadie; su historial se conserva).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('inscripciones_grupo', function (Blueprint $tabla): void {
            // Día del negocio desde el que deja de venir; vacío = sigue inscrita.
            $tabla->date('baja_desde')->nullable();
            $tabla->timestamp('baja_en')->nullable();
        });
        Schema::connection('tenant')->table('grupos', function (Blueprint $tabla): void {
            $tabla->timestamp('cerrado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('inscripciones_grupo', function (Blueprint $tabla): void {
            $tabla->dropColumn(['baja_desde', 'baja_en']);
        });
        Schema::connection('tenant')->table('grupos', function (Blueprint $tabla): void {
            $tabla->dropColumn('cerrado_en');
        });
    }
};
