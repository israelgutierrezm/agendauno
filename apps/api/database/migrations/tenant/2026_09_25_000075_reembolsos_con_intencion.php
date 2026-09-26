<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Reembolsos tolerantes a fallos y reintentos (fase 1, punto 1.1):
| - cada devolución se registra ANTES de pedirla a la pasarela; su ulid es la llave
|   con la que se pide, así reintentar nunca devuelve dos veces;
| - `llave`: la que manda la pantalla por intento (doble clic → la misma devolución);
| - `motivo_fallo`, `intentos`, y `aplicado_en` (fecha efectiva: cuándo se devolvió).
| Y la bandeja de incidencias de cobro: lo que necesita que alguien del negocio lo
| revise (p. ej. una devolución que la pasarela no confirmó).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('reembolsos', function (Blueprint $tabla): void {
            $tabla->string('llave', 100)->nullable();
            $tabla->string('motivo_fallo')->nullable();
            $tabla->unsignedSmallInteger('intentos')->default(0);
            $tabla->timestamp('aplicado_en')->nullable();

            $tabla->unique(['pago_id', 'llave']);
        });

        Schema::connection('tenant')->create('incidencias_cobro', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('tipo', 40);
            $tabla->string('estado', 20)->default('abierta'); // abierta | resuelta
            $tabla->foreignId('pago_id')->nullable()->constrained('pagos')->nullOnDelete();
            $tabla->foreignId('reembolso_id')->nullable()->constrained('reembolsos')->nullOnDelete();
            $tabla->foreignId('orden_id')->nullable()->constrained('ordenes')->nullOnDelete();
            $tabla->string('detalle', 500);
            $tabla->json('datos')->nullable();
            $tabla->foreignId('resuelta_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamp('resuelta_en')->nullable();
            $tabla->string('resolucion', 500)->nullable();
            $tabla->timestamps();

            $tabla->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('incidencias_cobro');

        Schema::connection('tenant')->table('reembolsos', function (Blueprint $tabla): void {
            $tabla->dropUnique(['pago_id', 'llave']);
            $tabla->dropColumn(['llave', 'motivo_fallo', 'intentos', 'aplicado_en']);
        });
    }
};
