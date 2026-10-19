<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Anticipo de una cita (ADR 0161). El servicio pide para apartar en línea un monto fijo
| (`anticipo_minor`) o un porcentaje de su precio (`anticipo_pct`), uno u otro. La cita
| guarda el anticipo con que se agendó (su regla de devolución no cambia si después
| cambia el servicio) y la orden del saldo, que queda por cobrar; `orden_id` sigue
| siendo la que se paga en línea para confirmarla.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('anticipo_minor')->nullable();
            $tabla->unsignedTinyInteger('anticipo_pct')->nullable();
        });
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('anticipo_minor')->nullable();
            $tabla->unsignedBigInteger('saldo_orden_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->dropIndex(['saldo_orden_id']);
            $tabla->dropColumn(['anticipo_minor', 'saldo_orden_id']);
        });
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->dropColumn(['anticipo_minor', 'anticipo_pct']);
        });
    }
};
