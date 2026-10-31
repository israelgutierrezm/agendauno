<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| La versión del aviso de privacidad del negocio con la que se agendó sin cuenta
| (ADR 0234): la evidencia de qué aviso tuvo a la vista quien dejó sus datos.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('aviso_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->dropColumn('aviso_version');
        });
    }
};
