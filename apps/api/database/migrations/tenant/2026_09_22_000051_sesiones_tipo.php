<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): TIPO de sesión. `clase` = sesión abierta con cupo que
 * cualquiera puede reservar; `cita` = sesión privada materializada PARA una persona
 * (se agenda desde un hueco de disponibilidad). Una cita nunca se lista a otros
 * miembros ni en el escaparate, y se libera cuando su reserva termina.
 *
 * Relleno: las sesiones de cupo 1, fuera de una serie y con alguna reserva nacieron
 * como citas (el agendado crea la sesión y su reserva a la vez).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('sesiones', function (Blueprint $tabla): void {
            $tabla->string('tipo', 16)->default('clase')->index();
        });

        DB::connection('tenant')->table('sesiones')
            ->where('capacidad', 1)
            ->whereNull('serie_id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('reservas')->whereColumn('reservas.sesion_id', 'sesiones.id'))
            ->update(['tipo' => 'cita']);
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sesiones', function (Blueprint $tabla): void {
            $tabla->dropIndex(['tipo']);
            $tabla->dropColumn('tipo');
        });
    }
};
