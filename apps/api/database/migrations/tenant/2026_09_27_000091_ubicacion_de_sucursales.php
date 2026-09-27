<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Dónde está cada sucursal, en coordenadas: el clima del Inicio del alumno (el
| pronóstico para su próxima clase o cita sale de aquí) y cualquier mapa después.
| Opcional: sin coordenadas, simplemente no hay pronóstico de esa sede.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('sucursales', function (Blueprint $tabla): void {
            // 7 decimales: centímetros, de sobra para ubicar un local.
            $tabla->decimal('latitud', 10, 7)->nullable();
            $tabla->decimal('longitud', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sucursales', function (Blueprint $tabla): void {
            $tabla->dropColumn(['latitud', 'longitud']);
        });
    }
};
