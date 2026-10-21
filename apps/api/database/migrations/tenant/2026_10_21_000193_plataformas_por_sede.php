<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Wellhub y TotalPass por sede (ADR 0202): cada sede de un negocio es otro gimnasio en
| Wellhub (su Gym ID) y otro «place» en TotalPass (su llave). Una integración es ahora la
| conexión de una plataforma con una sede (`sucursal_id`; sin sede, la de todo el negocio):
|
| - `integraciones`: una por plataforma y sede.
| - `publicaciones_plataforma.integracion_id`: las clases que publica cada conexión (los
|   productos o planes son de cada gimnasio).
| - `eventos_plataforma.integracion_id`: de qué conexión llegó cada aviso (su dirección
|   lleva el token de la conexión), para validarlo con sus llaves.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('integraciones', function (Blueprint $t): void {
            $t->dropUnique(['proveedor']);
            $t->unique(['proveedor', 'sucursal_id']);
        });
        Schema::connection('tenant')->table('publicaciones_plataforma', function (Blueprint $t): void {
            $t->unsignedBigInteger('integracion_id')->nullable()->index();
        });
        Schema::connection('tenant')->table('eventos_plataforma', function (Blueprint $t): void {
            $t->unsignedBigInteger('integracion_id')->nullable()->index();
        });

        // Hasta hoy había una conexión por plataforma: lo publicado es de esa.
        $db = DB::connection('tenant');
        foreach ($db->table('integraciones')->get(['id', 'proveedor']) as $integracion) {
            $db->table('publicaciones_plataforma')->where('proveedor', $integracion->proveedor)->whereNull('integracion_id')
                ->update(['integracion_id' => $integracion->id]);
            $db->table('eventos_plataforma')->where('proveedor', $integracion->proveedor)->whereNull('integracion_id')
                ->update(['integracion_id' => $integracion->id]);
        }

        Schema::connection('tenant')->table('publicaciones_plataforma', function (Blueprint $t): void {
            $t->dropUnique(['proveedor', 'oferta_id']);
            $t->unique(['proveedor', 'integracion_id', 'oferta_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('publicaciones_plataforma', function (Blueprint $t): void {
            $t->dropUnique(['proveedor', 'integracion_id', 'oferta_id']);
            $t->unique(['proveedor', 'oferta_id']);
            $t->dropIndex(['integracion_id']);
            $t->dropColumn('integracion_id');
        });
        Schema::connection('tenant')->table('eventos_plataforma', function (Blueprint $t): void {
            $t->dropIndex(['integracion_id']);
            $t->dropColumn('integracion_id');
        });
        Schema::connection('tenant')->table('integraciones', function (Blueprint $t): void {
            $t->dropUnique(['proveedor', 'sucursal_id']);
            $t->unique(['proveedor']);
        });
    }
};
