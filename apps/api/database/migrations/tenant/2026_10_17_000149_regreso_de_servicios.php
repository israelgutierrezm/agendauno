<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cada cuántos días suele regresar un cliente por un servicio (un corte, cada 21): con
| eso, retención encuentra a quien ya debería haber vuelto. Sin dato, no se busca.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->unsignedSmallInteger('regreso_dias')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->dropColumn('regreso_dias');
        });
    }
};
