<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): PAGO-PARA-RESERVAR (citas). Una orden puede ser por una
 * SESIÓN (no un producto): `ordenes.sesion_id`. Y una reserva pendiente de pago se liga
 * a su orden: `reservas.orden_id`. Al pagar esa orden, el fulfillment CONFIRMA la
 * reserva (en vez de conceder un derecho). SQLite (dev/test) no admite FK vía ALTER:
 * columna simple + índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('sesion_id')->nullable()->index();
        });

        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('orden_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->dropIndex(['orden_id']);
            $tabla->dropColumn('orden_id');
        });

        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->dropIndex(['sesion_id']);
            $tabla->dropColumn('sesion_id');
        });
    }
};
