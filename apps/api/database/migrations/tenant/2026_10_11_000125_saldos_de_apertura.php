<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldos de apertura al migrar de otro sistema (plan de mejoras C07): el plan que una
 * persona ya traía se carga con su saldo y su vigencia al corte acordado, sin inventar
 * una venta ni un pago. `origen` = `apertura` lo distingue de lo vendido aquí (null) y
 * `referencia_apertura` (huella de corte, persona y plan) evita cargarlo dos veces al
 * repetir el archivo.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('acuerdos', function (Blueprint $tabla): void {
            $tabla->string('origen', 20)->nullable();
            $tabla->string('referencia_apertura', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('acuerdos', function (Blueprint $tabla): void {
            $tabla->dropUnique(['referencia_apertura']);
            $tabla->dropColumn(['origen', 'referencia_apertura']);
        });
    }
};
