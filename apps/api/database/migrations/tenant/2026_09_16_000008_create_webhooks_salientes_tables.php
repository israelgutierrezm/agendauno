<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): WEBHOOKS SALIENTES (R40), primer consumidor del outbox
 * (R39). El estudio registra endpoints firmados a los que AgendaUno entrega sus
 * eventos de dominio; cada intento de entrega se registra en `entregas_webhook`
 * (at-least-once, con reintentos). El `secreto` (HMAC) se guarda cifrado y solo se
 * muestra al crearlo. Sin `tenant_id`: aislamiento por base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('webhooks_salientes', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('url');
            $tabla->text('secreto');          // cifrado en el modelo (HMAC-SHA256)
            $tabla->json('eventos')->nullable(); // lista de tipos suscritos; NULL = todos
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();
        });

        Schema::connection('tenant')->create('entregas_webhook', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('webhook_id')->constrained('webhooks_salientes')->cascadeOnDelete();
            $tabla->string('evento_ulid');
            $tabla->string('evento_tipo');
            $tabla->json('payload')->nullable();
            $tabla->string('estado')->default('pendiente'); // pendiente | entregado | fallido
            $tabla->unsignedSmallInteger('http_status')->nullable();
            $tabla->unsignedInteger('intentos')->default(0);
            $tabla->string('ultimo_error')->nullable();
            $tabla->dateTime('entregado_en')->nullable();
            $tabla->timestamps();

            $tabla->index(['estado', 'id']);
            $tabla->index('webhook_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('entregas_webhook');
        Schema::connection('tenant')->dropIfExists('webhooks_salientes');
    }
};
