<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Google Calendar y Outlook en las dos direcciones (ADR 0199). Quien atiende conecta su
| calendario: sus clases y citas se publican en un calendario propio del negocio dentro
| de su cuenta, y lo que tiene ocupado en su calendario principal bloquea su agenda
| aquí. Las llaves de su cuenta se guardan cifradas.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('calendarios_externos', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $t->string('proveedor', 20);
            $t->string('cuenta', 190)->nullable();
            $t->text('token_refresco');
            $t->text('token_acceso')->nullable();
            $t->timestamp('token_vence_en')->nullable();
            // El calendario del negocio en su cuenta, donde se publican sus clases y citas.
            $t->string('calendario_id', 255)->nullable();
            $t->string('estado', 20)->default('activo');
            $t->string('ultimo_error', 300)->nullable();
            $t->timestamp('sincronizado_en')->nullable();
            $t->timestamps();

            $t->unique(['usuario_id', 'proveedor']);
        });

        Schema::connection('tenant')->create('eventos_calendario_externo', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('calendario_externo_id')->constrained('calendarios_externos')->cascadeOnDelete();
            $t->foreignId('sesion_id')->constrained('sesiones')->cascadeOnDelete();
            $t->string('evento_id', 255);
            $t->string('huella', 64);
            $t->timestamps();

            // Nombre propio: el automático pasa de los 64 caracteres que acepta MySQL.
            $t->unique(['calendario_externo_id', 'sesion_id'], 'eventos_calendario_sesion_unique');
        });

        Schema::connection('tenant')->table('bloqueos_agenda', function (Blueprint $t): void {
            // Los que trae la sincronización de un calendario externo: se reemplazan solos.
            $t->foreignId('calendario_externo_id')->nullable()->constrained('calendarios_externos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('bloqueos_agenda', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('calendario_externo_id');
        });
        Schema::connection('tenant')->dropIfExists('eventos_calendario_externo');
        Schema::connection('tenant')->dropIfExists('calendarios_externos');
    }
};
