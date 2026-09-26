<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Quién canceló una reserva (fase 1, punto 1.4): el cliente, el negocio o el sistema,
| con qué usuario y cuándo. Solo la cancelación del cliente puede penalizar su
| crédito, y el historial debe poder explicar cada cambio de saldo.
|
| Lo anterior se llena con lo que se sabe: cuándo (su última actualización) y, si
| venció sin pago, que la canceló el sistema. El resto queda sin atribuir.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->timestamp('cancelada_en')->nullable();
            $tabla->string('cancelada_por', 10)->nullable();
            $tabla->unsignedBigInteger('cancelada_por_usuario_id')->nullable();
        });

        DB::connection('tenant')->table('reservas')
            ->where('estado', 'cancelada')
            ->whereNull('cancelada_en')
            ->update(['cancelada_en' => DB::raw('updated_at')]);
        DB::connection('tenant')->table('reservas')
            ->where('estado', 'cancelada')
            ->where('motivo_cancelacion', 'vencio_pago')
            ->update(['cancelada_por' => 'sistema']);
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->dropColumn(['cancelada_en', 'cancelada_por', 'cancelada_por_usuario_id']);
        });
    }
};
