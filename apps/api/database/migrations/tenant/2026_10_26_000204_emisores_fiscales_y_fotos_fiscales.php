<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Emisores versionados y fotos fiscales (ADR 0228):
|
| - `emisores_fiscales`: cada organización de FacturAPI con la que el negocio timbró, con
|   su RFC y su llave (cifrada). Cambiar de RFC no la borra: deja de ser la vigente
|   (`reemplazado_en`) y sus facturas se siguen descargando, reenviando y cancelando
|   con ella.
| - En cada factura, con qué emisor y con qué RFC se timbró.
| - En los datos fiscales, el RFC que FacturAPI dice que trae el sello (CSD): si no es el
|   capturado, no se timbra.
| - En cada línea de orden, cada línea del mostrador y en la orden de una cita (sin
|   líneas), la foto fiscal de lo vendido al venderse: el IVA (general, exento o cero) y
|   las claves del SAT de producto y de unidad. Sin foto (ventas de antes), se factura
|   con lo del catálogo.
|
| Las facturas que ya hay se ligan al emisor de hoy: con él se timbraron (el emisor de
| antes de un cambio de RFC ya no se puede saber aquí; ver el ADR).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('emisores_fiscales', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('rfc', 13);
            $tabla->string('razon_social');
            $tabla->string('facturapi_organizacion_id')->nullable();
            $tabla->text('facturapi_llave')->nullable(); // cifrada en la app
            $tabla->timestamp('reemplazado_en')->nullable()->index();
            $tabla->timestamps();
        });
        Schema::connection('tenant')->table('facturas', function (Blueprint $tabla): void {
            $tabla->foreignId('emisor_fiscal_id')->nullable()->constrained('emisores_fiscales')->nullOnDelete();
            $tabla->string('emisor_rfc', 13)->nullable();
        });
        Schema::connection('tenant')->table('datos_fiscales', function (Blueprint $tabla): void {
            $tabla->string('rfc_del_sello', 13)->nullable();
        });
        foreach (['lineas_orden', 'lineas_venta_pos', 'ordenes'] as $tabla) {
            Schema::connection('tenant')->table($tabla, function (Blueprint $t): void {
                $t->string('iva', 10)->nullable();
                $t->string('clave_prod_serv', 8)->nullable();
                $t->string('clave_unidad', 3)->nullable();
            });
        }

        // El emisor de hoy, con la llave tal cual (ya va cifrada con la misma llave de la
        // aplicación), y las facturas timbradas que ya hay, ligadas a él.
        $datos = DB::connection('tenant')->table('datos_fiscales')->orderBy('id')->first();
        $timbradas = DB::connection('tenant')->table('facturas')->whereNotNull('facturapi_id')->exists();
        if ($datos !== null && ($timbradas || ($datos->facturapi_organizacion_id ?? null) !== null)) {
            $ahora = now();
            $emisor = DB::connection('tenant')->table('emisores_fiscales')->insertGetId([
                'ulid' => strtolower((string) Str::ulid()),
                'rfc' => $datos->rfc,
                'razon_social' => $datos->razon_social,
                'facturapi_organizacion_id' => $datos->facturapi_organizacion_id,
                'facturapi_llave' => $datos->facturapi_llave,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            DB::connection('tenant')->table('facturas')->whereNotNull('facturapi_id')
                ->update(['emisor_fiscal_id' => $emisor, 'emisor_rfc' => $datos->rfc]);
        }
    }

    public function down(): void
    {
        foreach (['lineas_orden', 'lineas_venta_pos', 'ordenes'] as $tabla) {
            Schema::connection('tenant')->table($tabla, function (Blueprint $t): void {
                $t->dropColumn(['iva', 'clave_prod_serv', 'clave_unidad']);
            });
        }
        Schema::connection('tenant')->table('datos_fiscales', function (Blueprint $tabla): void {
            $tabla->dropColumn('rfc_del_sello');
        });
        Schema::connection('tenant')->table('facturas', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('emisor_fiscal_id');
            $tabla->dropColumn('emisor_rfc');
        });
        Schema::connection('tenant')->dropIfExists('emisores_fiscales');
    }
};
