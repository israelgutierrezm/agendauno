<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Mostrador (ADR 0139):
| - extras de un artículo (p. ej. a una proteína: fresas, arándanos, leche de soya),
|   con su precio; cada línea vendida guarda los que llevó, congelados;
| - la propina de una venta, aparte de lo vendido, y para quién es;
| - lo que entregó el cliente en efectivo (para el cambio).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('extras_articulo', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->unsignedBigInteger('articulo_id');
            $tabla->string('nombre', 80);
            $tabla->unsignedBigInteger('precio_minor')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->timestamps();

            $tabla->index(['articulo_id', 'activo']);
        });

        Schema::connection('tenant')->table('lineas_venta_pos', function (Blueprint $tabla): void {
            $tabla->json('extras')->nullable();
            $tabla->unsignedBigInteger('extras_minor')->default(0);
        });

        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('propina_minor')->default(0);
            $tabla->unsignedBigInteger('propina_para_id')->nullable();
            $tabla->unsignedBigInteger('recibido_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->dropColumn(['propina_minor', 'propina_para_id', 'recibido_minor']);
        });
        Schema::connection('tenant')->table('lineas_venta_pos', function (Blueprint $tabla): void {
            $tabla->dropColumn(['extras', 'extras_minor']);
        });
        Schema::connection('tenant')->dropIfExists('extras_articulo');
    }
};
