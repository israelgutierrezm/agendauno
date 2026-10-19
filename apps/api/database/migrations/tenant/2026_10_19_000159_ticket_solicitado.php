<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cuándo se pidió mandar el ticket de una venta (R06): el correo sale por la cola, así
| que pedirlo no es haberlo mandado. Con esto el ticket está `pendiente` hasta que la
| cola lo manda (`ticket_enviado_en`) o agota sus intentos (`ticket_envio_fallido_en`).
| Null = anterior o nunca pedido.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $t): void {
            $t->timestamp('ticket_solicitado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $t): void {
            $t->dropColumn('ticket_solicitado_en');
        });
    }
};
