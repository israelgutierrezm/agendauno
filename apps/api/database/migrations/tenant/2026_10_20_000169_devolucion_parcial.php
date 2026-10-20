<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Devolución parcial en el mostrador (ADR 0178): algunos artículos o algunas piezas de
| una venta regresan; el dinero sale de la caja abierta por una forma de la venta y
| las piezas vuelven al inventario. La venta guarda cuánto se devolvió de cada línea.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('devoluciones_venta', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('venta_pos_id')->constrained('ventas_pos')->cascadeOnDelete();
            $tabla->unsignedBigInteger('monto_minor');
            $tabla->string('metodo', 20);
            $tabla->string('motivo', 255);
            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            // Una por intento desde la pantalla: repetirla no devuelve dos veces.
            $tabla->string('llave', 100)->nullable();
            $tabla->timestamps();
            $tabla->unique(['venta_pos_id', 'llave']);
            $tabla->index('created_at');
        });

        Schema::connection('tenant')->create('lineas_devolucion_venta', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('devolucion_venta_id')->constrained('devoluciones_venta')->cascadeOnDelete();
            $tabla->foreignId('linea_venta_pos_id')->constrained('lineas_venta_pos')->cascadeOnDelete();
            $tabla->unsignedInteger('cantidad');
            $tabla->unsignedBigInteger('monto_minor');
            $tabla->timestamps();
        });

        Schema::connection('tenant')->table('lineas_venta_pos', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('cantidad_devuelta')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('lineas_venta_pos', function (Blueprint $tabla): void {
            $tabla->dropColumn('cantidad_devuelta');
        });
        Schema::connection('tenant')->dropIfExists('lineas_devolucion_venta');
        Schema::connection('tenant')->dropIfExists('devoluciones_venta');
    }
};
