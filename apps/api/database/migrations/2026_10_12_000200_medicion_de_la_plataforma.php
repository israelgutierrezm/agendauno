<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Medición del SaaS con GA4 y Meta (ADR 0143):
| - `destinos_medicion_plataforma`: la propiedad de GA4 y el píxel de Meta de cada
|   producto (AgendaUno y TurnoUno, por separado), con su secreto cifrado.
| - `envios_medicion_plataforma`: cada conversión del embudo (negocio creado, prueba
|   iniciada, activación operativa, primera suscripción pagada) por destino, con su
|   estado y su identificador estable (no se manda dos veces).
| - `contextos_medicion_plataforma`: las preferencias de quien registró el negocio y
|   los identificadores de su navegador que permitió.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinos_medicion_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('producto', 20);
            $tabla->string('proveedor', 10);
            $tabla->string('identificador', 40)->nullable();
            $tabla->text('secreto')->nullable();
            $tabla->string('codigo_prueba', 40)->nullable();
            $tabla->boolean('activo')->default(false);
            $tabla->timestamp('activo_desde')->nullable();
            $tabla->timestamp('aviso_confirmado_en')->nullable();
            $tabla->timestamp('ultimo_envio_en')->nullable();
            $tabla->string('ultimo_error', 250)->nullable();
            $tabla->timestamp('ultimo_error_en')->nullable();
            $tabla->timestamps();

            $tabla->unique(['producto', 'proveedor']);
        });

        Schema::create('envios_medicion_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('destino_id')->constrained('destinos_medicion_plataforma')->cascadeOnDelete();
            $tabla->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            $tabla->string('evento', 40);
            $tabla->string('nombre', 50);
            $tabla->string('id_evento', 100);
            $tabla->timestamp('ocurrido_en');
            $tabla->string('accion', 20)->default('website');
            $tabla->boolean('personalizado')->default(false);
            $tabla->unsignedBigInteger('valor_minor')->nullable();
            $tabla->char('moneda', 3)->nullable();
            $tabla->string('transaccion', 40)->nullable();
            $tabla->json('items')->nullable();
            $tabla->json('parametros')->nullable();
            $tabla->string('estado', 12)->default('pendiente');
            $tabla->string('motivo', 30)->nullable();
            $tabla->unsignedSmallInteger('intentos')->default(0);
            $tabla->timestamp('proximo_intento_en')->nullable();
            $tabla->timestamp('enviado_en')->nullable();
            $tabla->unsignedSmallInteger('http_status')->nullable();
            $tabla->string('ultimo_error', 250)->nullable();
            $tabla->timestamps();

            $tabla->unique(['destino_id', 'id_evento']);
            $tabla->index(['estado', 'proximo_intento_en']);
        });

        Schema::table('contextos_medicion_plataforma', function (Blueprint $tabla): void {
            $tabla->string('visitante', 32)->nullable()->index();
            $tabla->boolean('analitica')->default(false);
            $tabla->boolean('publicidad')->default(false);
            $tabla->timestamp('consentimiento_en')->nullable();
            $tabla->string('ga_client_id', 64)->nullable();
            $tabla->string('ga_session_id', 32)->nullable();
            $tabla->string('fbp', 100)->nullable();
            $tabla->string('fbc', 600)->nullable();
            $tabla->string('agente', 400)->nullable();
            $tabla->string('ip', 45)->nullable();
            $tabla->string('url', 300)->nullable();
            $tabla->timestamp('capturado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contextos_medicion_plataforma', function (Blueprint $tabla): void {
            $tabla->dropIndex(['visitante']);
            $tabla->dropColumn(['visitante', 'analitica', 'publicidad', 'consentimiento_en', 'ga_client_id', 'ga_session_id', 'fbp', 'fbc', 'agente', 'ip', 'url', 'capturado_en']);
        });
        Schema::dropIfExists('envios_medicion_plataforma');
        Schema::dropIfExists('destinos_medicion_plataforma');
    }
};
