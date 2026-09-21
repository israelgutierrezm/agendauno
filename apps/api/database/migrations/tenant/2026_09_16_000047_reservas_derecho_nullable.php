<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): `reservas.derecho_id` pasa a NULLABLE. Una reserva de
 * pago-para-reservar (citas) NO se respalda en un derecho/membresía — el acceso lo
 * habilita el pago de la orden ligada. Las reservas por membresía siguen llevando su
 * derecho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('derecho_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('derecho_id')->nullable(false)->change();
        });
    }
};
