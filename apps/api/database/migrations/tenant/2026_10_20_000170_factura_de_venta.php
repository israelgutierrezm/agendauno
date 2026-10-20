<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| La factura dice qué venta factura (ADR 0179): una orden (ya existía `orden_id`, que
| nunca se llenaba) o una venta del mostrador. Así una venta se factura una vez, el
| candado de «ya facturada» funciona al anular o corregir, y quien ve una sede ve sus
| facturas.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('facturas', function (Blueprint $tabla): void {
            $tabla->foreignId('venta_pos_id')->nullable()->constrained('ventas_pos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('facturas', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('venta_pos_id');
        });
    }
};
