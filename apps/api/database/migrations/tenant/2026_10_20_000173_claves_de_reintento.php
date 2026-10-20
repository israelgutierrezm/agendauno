<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Reintentos sin duplicar:
| - facturas.huella: la huella del CFDI que se pidió. Con ella se arma la llave de
|   idempotencia de FacturAPI; si no respondió a tiempo, el reintento recibe la misma
|   factura en lugar de timbrar otra.
| - movimientos_caja.clave: la del retiro o ingreso de efectivo; repetirlo (sin red) no
|   lo registra dos veces.
| - ordenes.clave: la de una compra del cliente desde su cuenta; repetirla (el negocio
|   tardó en responder) devuelve la misma orden.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('facturas', function (Blueprint $tabla): void {
            $tabla->string('huella', 40)->nullable()->index();
        });
        Schema::connection('tenant')->table('movimientos_caja', function (Blueprint $tabla): void {
            $tabla->string('clave', 64)->nullable();
            $tabla->unique(['turno_caja_id', 'clave']);
        });
        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->string('clave', 64)->nullable();
            $tabla->unique(['persona_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->dropUnique(['persona_id', 'clave']);
            $tabla->dropColumn('clave');
        });
        Schema::connection('tenant')->table('movimientos_caja', function (Blueprint $tabla): void {
            $tabla->dropUnique(['turno_caja_id', 'clave']);
            $tabla->dropColumn('clave');
        });
        Schema::connection('tenant')->table('facturas', function (Blueprint $tabla): void {
            $tabla->dropIndex(['huella']);
            $tabla->dropColumn('huella');
        });
    }
};
