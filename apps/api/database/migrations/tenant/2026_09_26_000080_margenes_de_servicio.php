<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Tiempos de preparación y limpieza (fase 2, punto 2.3). El servicio define cuánto
| antes y cuánto después ocupa la agenda además de la atención. Cada sesión congela
| sus márgenes y guarda el tramo que ocupa (`ocupa_desde`/`ocupa_hasta`); los choques
| y la disponibilidad se calculan sobre ese tramo, y al cliente se le sigue
| comunicando `inicia_en`/`termina_en`.
|
| Lo anterior no tenía márgenes: lo ocupado es la atención misma.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->unsignedSmallInteger('preparacion_min')->default(0);
            $tabla->unsignedSmallInteger('limpieza_min')->default(0);
        });
        Schema::connection('tenant')->table('sesiones', function (Blueprint $tabla): void {
            $tabla->unsignedSmallInteger('margen_antes_min')->default(0);
            $tabla->unsignedSmallInteger('margen_despues_min')->default(0);
            $tabla->timestamp('ocupa_desde')->nullable();
            $tabla->timestamp('ocupa_hasta')->nullable();
        });

        DB::connection('tenant')->table('sesiones')->update([
            'ocupa_desde' => DB::raw('inicia_en'),
            'ocupa_hasta' => DB::raw('termina_en'),
        ]);

        Schema::connection('tenant')->table('sesiones', function (Blueprint $tabla): void {
            $tabla->index(['instructor_id', 'ocupa_desde']);
            $tabla->index(['recurso_id', 'ocupa_desde']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sesiones', function (Blueprint $tabla): void {
            $tabla->dropIndex(['instructor_id', 'ocupa_desde']);
            $tabla->dropIndex(['recurso_id', 'ocupa_desde']);
            $tabla->dropColumn(['margen_antes_min', 'margen_despues_min', 'ocupa_desde', 'ocupa_hasta']);
        });
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->dropColumn(['preparacion_min', 'limpieza_min']);
        });
    }
};
