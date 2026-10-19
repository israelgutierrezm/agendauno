<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cobro de una cita como una sola operación (R04–R06 de la revisión del 2026-10-18):
| todo cobro de cita deja su venta (aun solo el servicio), ligada a su reserva; la clave
| del intento va con la huella de lo pedido (otra cita u otros importes con la misma
| clave son un conflicto, no un éxito ajeno); y el envío del ticket tiene su estado,
| aparte del cobro.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('reserva_id')->nullable()->index();
            $tabla->string('huella_intento', 64)->nullable();
            $tabla->timestamp('ticket_envio_fallido_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->dropIndex(['reserva_id']);
            $tabla->dropColumn(['reserva_id', 'huella_intento', 'ticket_envio_fallido_en']);
        });
    }
};
