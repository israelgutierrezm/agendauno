<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| AgendaUno como sistema integrado de Wellhub (ADR 0202): cuando un gimnasio elige a
| AgendaUno en Wellhub, Wellhub avisa a la plataforma (`SYSTEM_INTEGRATION_REQUESTED`)
| con su Gym ID y los campos que pide AgendaUno. Si uno de esos campos es el código de un
| negocio, el Gym ID se le pone solo; si no, el superadmin lo asigna.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_wellhub', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            // El del aviso: el mismo aviso otra vez es la misma solicitud.
            $t->string('evento_id', 64)->unique();
            $t->string('gym_id', 40);
            $t->string('gimnasio', 160)->nullable();
            $t->string('socio_id', 64)->nullable();
            $t->json('campos')->nullable();
            $t->foreignId('estudio_id')->nullable()->constrained('estudios')->nullOnDelete();
            // pendiente | asignada | descartada
            $t->string('estado', 20)->default('pendiente');
            $t->string('nota', 300)->nullable();
            $t->timestamp('recibida_en');
            $t->timestamp('atendida_en')->nullable();
            $t->timestamps();

            $t->index(['estado', 'recibida_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_wellhub');
    }
};
