<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): OUTBOX de eventos de dominio (R39, habilitador de P1).
 * Los eventos importantes se ESCRIBEN en esta tabla DENTRO de la misma transaccion
 * que cambia el estado (atomico: ni se pierden ni se inventan). Un relay
 * (agendauno:despachar-outbox) los publica despues (at-least-once; los consumidores
 * deben ser idempotentes) hacia comunicaciones/webhooks/analitica/automatizacion.
 * Sin `tenant_id`: aislamiento por base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('eventos_outbox', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('tipo');                 // p. ej. reserva.creada, pago.reembolsado
            $tabla->string('agregado_tipo');        // reserva, pago, derecho...
            $tabla->string('agregado_id')->nullable();
            $tabla->json('payload')->nullable();
            $tabla->string('correlation_id')->nullable();
            $tabla->dateTime('ocurrido_en');
            $tabla->dateTime('publicado_en')->nullable();
            $tabla->unsignedInteger('intentos')->default(0);
            $tabla->string('ultimo_error')->nullable();
            $tabla->timestamps();

            // El relay busca lo no publicado en orden de ocurrencia.
            $tabla->index(['publicado_en', 'id']);
            $tabla->index(['agregado_tipo', 'agregado_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('eventos_outbox');
    }
};
