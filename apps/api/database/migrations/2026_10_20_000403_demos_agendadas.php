<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Demos de 15 minutos que se agendan desde la landing (ADR 0193). `ranura` es el
| horario y el lugar que ocupa (`{inicio UTC}#{n}`): único, así dos personas no toman
| el mismo aunque agenden a la vez. Al cancelarla se libera (null).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demos_agendadas', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('producto', 20);
            $t->dateTime('inicia_en');
            $t->string('ranura', 40)->nullable()->unique();
            $t->string('nombre', 120);
            $t->string('negocio', 120);
            $t->string('giro', 40)->nullable();
            $t->string('correo', 190);
            $t->string('telefono', 30);
            // Pidió que se le confirme por WhatsApp (su consentimiento, no por omisión).
            $t->boolean('acepta_whatsapp')->default(false);
            $t->string('estado', 20)->default('agendada');
            $t->string('token', 64);
            $t->unsignedInteger('aviso_version')->nullable();
            $t->timestamp('acepto_aviso_en')->nullable();
            $t->timestamps();

            $t->index(['estado', 'inicia_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demos_agendadas');
    }
};
