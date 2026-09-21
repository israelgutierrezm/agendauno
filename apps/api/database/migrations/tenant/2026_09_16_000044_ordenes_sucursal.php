<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): atribución de sucursal a las ÓRDENES (R19). Permite
 * segmentar ingresos por sede y acotar la venta al staff limitado a sus sucursales.
 * Se llena en la creación (sede del vendedor acotado o, si no, la sede de casa del
 * comprador). SQLite (dev/test) no admite FK vía ALTER: columna simple + índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('sucursal_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->dropIndex(['sucursal_id']);
            $tabla->dropColumn('sucursal_id');
        });
    }
};
