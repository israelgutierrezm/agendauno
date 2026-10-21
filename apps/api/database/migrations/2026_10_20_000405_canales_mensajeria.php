<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| La bandeja de WhatsApp e Instagram (ADR 0200): el número de WhatsApp o la cuenta de
| Instagram que conecta cada negocio. Vive en el control plane porque Meta avisa a una
| sola URL de la plataforma y hay que saber de qué negocio es cada mensaje (por el id
| del número o de la cuenta). La llave va cifrada.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canales_mensajeria', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            $t->string('canal', 20);
            // El id del número (WhatsApp) o de la cuenta profesional (Instagram).
            $t->string('cuenta_id', 64);
            $t->string('waba_id', 64)->nullable();
            // Lo que se muestra: el número o el @usuario.
            $t->string('nombre', 120)->nullable();
            $t->text('token');
            $t->timestamp('token_vence_en')->nullable();
            $t->text('pin')->nullable();
            $t->string('estado', 20)->default('activo');
            $t->string('ultimo_error', 300)->nullable();
            $t->timestamps();

            $t->unique(['estudio_id', 'canal']);
            $t->unique(['canal', 'cuenta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canales_mensajeria');
    }
};
