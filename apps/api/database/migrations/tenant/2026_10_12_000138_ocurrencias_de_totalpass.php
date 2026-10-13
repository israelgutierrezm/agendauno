<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Reservas desde la app de TotalPass (ADR 0149). En TotalPass la fecha, la hora y el plan
| de una ocurrencia no se cambian en su lugar: si cambian, se retira y se publica de
| nuevo. `fija` es la huella de eso que no se cambia, para saber cuándo hay que hacerlo.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('horarios_plataforma', function (Blueprint $tabla): void {
            $tabla->string('fija', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('horarios_plataforma', function (Blueprint $tabla): void {
            $tabla->dropColumn('fija');
        });
    }
};
