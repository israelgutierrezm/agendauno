<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\ParametrosTenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| La mensualidad de la app con marca se cobra al precio del día en que se encendió (ADR
| 0231): subir el precio publicado no toca lo ya contratado. Quien ya la tuvo encendida
| se queda con el precio de hoy.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('app_marca_mensual_minor')->nullable()->after('app_marca_hasta');
        });

        // Los parámetros de la plataforma van cifrados: se leen con su servicio.
        $precio = app(ParametrosTenant::class)->entero('servicios.app_marca_mensual_centavos');

        DB::table('estudios')
            ->where(static fn ($q) => $q->whereNotNull('app_marca_desde')->orWhere('modulos', 'like', '%"app_marca"%'))
            ->update(['app_marca_mensual_minor' => $precio]);
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn('app_marca_mensual_minor');
        });
    }
};
