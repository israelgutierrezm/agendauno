<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Reprogramar desde la cuenta del cliente (ADR 0044): cuántas veces cambió él mismo
| el horario de una reserva, para respetar el máximo que fija el negocio.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->unsignedSmallInteger('reprogramaciones_cliente')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->dropColumn('reprogramaciones_cliente');
        });
    }
};
