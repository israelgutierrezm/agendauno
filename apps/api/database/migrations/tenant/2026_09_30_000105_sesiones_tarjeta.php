<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Sesiones de la pasarela para autorizar una tarjeta de pago automático (ADR 0076):
| si el aviso de la pasarela no llega, la conciliación pregunta cómo terminó cada
| una y registra la tarjeta como lo habría hecho el aviso.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('sesiones_tarjeta', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $tabla->string('proveedor', 20);
            $tabla->string('referencia', 191)->unique();
            // pendiente | completada | expirada
            $tabla->string('estado', 12)->default('pendiente');
            $tabla->timestamp('revisada_en')->nullable();
            $tabla->timestamps();

            $tabla->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sesiones_tarjeta');
    }
};
