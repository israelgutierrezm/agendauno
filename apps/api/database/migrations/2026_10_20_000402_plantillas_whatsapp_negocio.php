<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Las plantillas de WhatsApp de cada negocio (ADR 0190): las escribe el negocio para
| sus campañas y la plataforma las manda a aprobar a Meta en su cuenta de WhatsApp
| Business (de ahí que vivan en el control plane, como el número). Su estado sigue al
| de Meta.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantillas_whatsapp_negocio', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            $t->string('titulo', 80);
            $t->string('categoria', 20);
            $t->text('cuerpo');
            $t->string('pie', 60)->nullable();
            $t->string('estado', 20)->default('borrador');
            $t->string('nombre_meta', 120)->unique();
            $t->string('id_meta', 64)->nullable();
            $t->string('motivo', 300)->nullable();
            $t->timestamp('enviada_en')->nullable();
            $t->timestamp('revisada_en')->nullable();
            $t->timestamps();

            $t->index(['estudio_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantillas_whatsapp_negocio');
    }
};
