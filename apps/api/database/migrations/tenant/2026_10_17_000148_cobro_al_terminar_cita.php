<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cobro al terminar una cita: el servicio (su orden), los productos y la propina en un
| solo cobro. La venta del mostrador queda ligada a la orden de la cita y guarda cuánto
| fue del servicio, para que el ticket y el cambio sean de todo lo cobrado.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('orden_id')->nullable()->index();
            $tabla->bigInteger('servicio_minor')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->dropIndex(['orden_id']);
            $tabla->dropColumn(['orden_id', 'servicio_minor']);
        });
    }
};
