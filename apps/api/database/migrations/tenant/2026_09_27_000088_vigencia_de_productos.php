<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Vigencia de productos (ADR 0050): además de "N días", "N meses a la misma fecha" y
| "hasta fin de mes". `vigencia_dias` pasa a `vigencia_tipo = dias` con su cantidad.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->string('vigencia_tipo', 20)->nullable()->after('creditos_incluidos');
            $tabla->unsignedSmallInteger('vigencia_cantidad')->nullable()->after('vigencia_tipo');
        });

        DB::connection('tenant')->table('productos_comerciales')->whereNotNull('vigencia_dias')->update([
            'vigencia_tipo' => 'dias',
            'vigencia_cantidad' => DB::raw('vigencia_dias'),
        ]);

        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->dropColumn('vigencia_dias');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('vigencia_dias')->nullable()->after('creditos_incluidos');
        });

        // Solo "N días" se puede expresar en el formato anterior.
        DB::connection('tenant')->table('productos_comerciales')->where('vigencia_tipo', 'dias')->update([
            'vigencia_dias' => DB::raw('vigencia_cantidad'),
        ]);

        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->dropColumn(['vigencia_tipo', 'vigencia_cantidad']);
        });
    }
};
