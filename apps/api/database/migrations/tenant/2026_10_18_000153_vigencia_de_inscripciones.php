<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Vigencia de una inscripción a un grupo (R03 de la revisión del 2026-10-18): desde qué
| día del negocio cuenta (`alta_desde`; null = desde siempre, las anteriores) hasta el
| día de su baja (`baja_desde`, que ya existía). Cada fecha del grupo se reserva solo
| si cae en ese intervalo, aunque la baja o el cierre se hayan registrado antes.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('inscripciones_grupo', function (Blueprint $tabla): void {
            $tabla->date('alta_desde')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('inscripciones_grupo', function (Blueprint $tabla): void {
            $tabla->dropColumn('alta_desde');
        });
    }
};
