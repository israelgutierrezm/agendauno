<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Medición de cada negocio con su GA4 y su Meta (ADR 0143), en su propia base:
| - `destinos_medicion`: su propiedad de GA4 y su píxel de Meta, con el secreto
|   cifrado, la confirmación de su aviso de privacidad y los eventos adicionales que
|   decide mandar (asistencia, renovación).
| - `envios_medicion`: cada conversión (solicitud, cuenta activada, compra,
|   devolución, reserva…) por destino, una sola vez, con su estado.
| - `contextos_medicion`: las preferencias de quien convirtió y los identificadores
|   de su navegador que permitió.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('destinos_medicion', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('proveedor', 10)->unique();
            $tabla->string('identificador', 40)->nullable();
            $tabla->text('secreto')->nullable();
            $tabla->string('codigo_prueba', 40)->nullable();
            $tabla->boolean('activo')->default(false);
            $tabla->timestamp('activo_desde')->nullable();
            $tabla->timestamp('aviso_confirmado_en')->nullable();
            $tabla->unsignedBigInteger('aviso_confirmado_por')->nullable();
            $tabla->json('eventos_extra')->nullable();
            $tabla->timestamp('ultimo_envio_en')->nullable();
            $tabla->string('ultimo_error', 250)->nullable();
            $tabla->timestamp('ultimo_error_en')->nullable();
            $tabla->timestamps();
        });

        Schema::connection('tenant')->create('envios_medicion', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->unsignedBigInteger('destino_id');
            $tabla->string('evento', 40);
            $tabla->string('nombre', 50);
            $tabla->string('id_evento', 100);
            $tabla->string('evento_ulid', 26)->nullable();
            $tabla->unsignedBigInteger('persona_id')->nullable();
            $tabla->unsignedBigInteger('contexto_medicion_id')->nullable();
            $tabla->timestamp('ocurrido_en');
            $tabla->string('accion', 20)->default('website');
            $tabla->boolean('personalizado')->default(false);
            $tabla->boolean('sensible')->default(false);
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
            $tabla->index(['evento', 'transaccion']);
        });

        Schema::connection('tenant')->table('contextos_medicion', function (Blueprint $tabla): void {
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
        Schema::connection('tenant')->table('contextos_medicion', function (Blueprint $tabla): void {
            $tabla->dropIndex(['visitante']);
            $tabla->dropColumn(['visitante', 'analitica', 'publicidad', 'consentimiento_en', 'ga_client_id', 'ga_session_id', 'fbp', 'fbc', 'agente', 'ip', 'url', 'capturado_en']);
        });
        Schema::connection('tenant')->dropIfExists('envios_medicion');
        Schema::connection('tenant')->dropIfExists('destinos_medicion');
    }
};
