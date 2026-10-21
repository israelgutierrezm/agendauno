<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Productos al reservar (ADR 0194): el negocio marca qué artículos del mostrador se
| ofrecen al reservar (agua, calcetines antiderrapantes) y el cliente los aparta para su
| clase; los recoge y los paga en el mostrador al llegar o al salir. El apartado guarda
| el precio que vio y, al cobrarse, la venta del mostrador.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('articulos', function (Blueprint $t): void {
            $t->boolean('ofrecer_al_reservar')->default(false);
        });

        Schema::connection('tenant')->create('apartados_articulo', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $t->foreignId('sesion_id')->constrained('sesiones')->cascadeOnDelete();
            $t->foreignId('reserva_id')->nullable()->constrained('reservas')->nullOnDelete();
            $t->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $t->foreignId('articulo_id')->constrained('articulos')->cascadeOnDelete();
            $t->unsignedSmallInteger('cantidad');
            $t->unsignedBigInteger('precio_minor');
            $t->char('moneda', 3);
            $t->string('estado', 20)->default('apartado');
            $t->foreignId('venta_pos_id')->nullable()->constrained('ventas_pos')->nullOnDelete();
            $t->timestamps();

            $t->index(['sesion_id', 'estado']);
            $t->index(['persona_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('apartados_articulo');
        Schema::connection('tenant')->table('articulos', function (Blueprint $t): void {
            $t->dropColumn('ofrecer_al_reservar');
        });
    }
};
