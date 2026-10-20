<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cobro mixto (ADR 0177): un cobro en caja pagado con varias formas. El pago de la orden
| y la venta del mostrador siguen siendo uno; sus partes dicen cuánto entró en cada
| forma (la caja y el corte las cuentan por separado). La devolución de un cobro mixto
| dice por qué forma salió el dinero.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('partes_cobro', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('pago_id')->nullable()->constrained('pagos')->cascadeOnDelete();
            $tabla->foreignId('venta_pos_id')->nullable()->constrained('ventas_pos')->cascadeOnDelete();
            $tabla->string('metodo', 20);
            $tabla->unsignedBigInteger('monto_minor');
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->timestamps();
            $tabla->index('pago_id');
            $tabla->index('venta_pos_id');
        });

        Schema::connection('tenant')->table('reembolsos', function (Blueprint $tabla): void {
            // De un cobro mixto: la forma por la que salió el dinero (null: la del pago).
            $tabla->string('metodo', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('reembolsos', function (Blueprint $tabla): void {
            $tabla->dropColumn('metodo');
        });
        Schema::connection('tenant')->dropIfExists('partes_cobro');
    }
};
