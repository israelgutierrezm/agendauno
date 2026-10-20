<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Los avisos de las pasarelas de cobro (Stripe, Mercado Pago, OpenPay; ADR 0173): la
| firma se comprueba al recibirlos, se guardan y se contesta al momento; se procesan
| aparte, con reintentos. `clave` es el id del aviso cuando la pasarela lo da (Stripe):
| el mismo aviso no se guarda dos veces.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('avisos_pasarela', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('proveedor', 20);
            $tabla->string('tipo', 80)->nullable();
            $tabla->string('clave', 120)->nullable();
            $tabla->json('payload');
            $tabla->string('estado', 20)->default('pendiente');
            $tabla->string('resultado', 300)->nullable();
            $tabla->unsignedInteger('intentos')->default(0);
            $tabla->timestamp('reintentar_en')->nullable();
            $tabla->timestamp('recibido_en');
            $tabla->timestamp('procesado_en')->nullable();
            $tabla->timestamps();

            $tabla->unique(['proveedor', 'clave']);
            $tabla->index(['estado', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('avisos_pasarela');
    }
};
