<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Días cerrados por sede (ADR 0230): cada uno es de una sede o de todo el negocio
| (`sucursal_id` null), que es lo que ya son los que existen. Uno por fecha y sede: un
| índice único trata cada NULL como distinto, así que `sede_clave` (una columna calculada:
| la sede o 0) hace que también haya uno solo de todo el negocio por fecha.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('excepciones_horario', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('sucursal_id')->nullable()->after('fecha');
        });
        Schema::connection('tenant')->table('excepciones_horario', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('sede_clave')->virtualAs('COALESCE(sucursal_id, 0)');
        });
        Schema::connection('tenant')->table('excepciones_horario', function (Blueprint $tabla): void {
            $tabla->dropUnique(['fecha']);
            $tabla->unique(['fecha', 'sede_clave']);
            $tabla->index('sucursal_id');
        });
    }

    public function down(): void
    {
        // Los de una sede no caben en el esquema anterior (uno por fecha).
        DB::connection('tenant')->table('excepciones_horario')->whereNotNull('sucursal_id')->delete();
        Schema::connection('tenant')->table('excepciones_horario', function (Blueprint $tabla): void {
            $tabla->dropIndex(['sucursal_id']);
            $tabla->dropUnique(['fecha', 'sede_clave']);
            $tabla->unique('fecha');
        });
        Schema::connection('tenant')->table('excepciones_horario', function (Blueprint $tabla): void {
            $tabla->dropColumn('sede_clave');
        });
        Schema::connection('tenant')->table('excepciones_horario', function (Blueprint $tabla): void {
            $tabla->dropColumn('sucursal_id');
        });
    }
};
