<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| La bandeja de WhatsApp e Instagram (ADR 0200): una conversación por persona y canal,
| con sus mensajes. Quien escribe puede no ser cliente aún; el equipo la vincula con su
| ficha a mano.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('conversaciones', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('canal', 20);
            // El número de WhatsApp o el id de Instagram de quien escribe.
            $t->string('contacto_id', 64);
            $t->string('contacto_nombre', 160)->nullable();
            $t->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $t->string('estado', 20)->default('abierta');
            $t->unsignedInteger('no_leidos')->default(0);
            $t->string('ultimo_texto', 200)->nullable();
            $t->timestamp('ultimo_mensaje_en')->nullable();
            // Desde aquí corren las 24 horas en que Meta deja contestar con texto libre.
            $t->timestamp('ultimo_entrante_en')->nullable();
            $t->timestamps();

            $t->unique(['canal', 'contacto_id']);
            $t->index(['estado', 'ultimo_mensaje_en']);
        });

        Schema::connection('tenant')->create('mensajes_conversacion', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('conversacion_id')->constrained('conversaciones')->cascadeOnDelete();
            $t->string('direccion', 10);
            $t->string('tipo', 20)->default('texto');
            $t->text('texto')->nullable();
            // El id que le da Meta: un aviso repetido no lo duplica.
            $t->string('id_externo', 190)->nullable()->unique();
            $t->string('estado', 20)->default('recibido');
            $t->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('enviado_en');
            $t->timestamps();

            $t->index(['conversacion_id', 'enviado_en']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('mensajes_conversacion');
        Schema::connection('tenant')->dropIfExists('conversaciones');
    }
};
