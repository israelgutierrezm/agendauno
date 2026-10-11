<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Acceso del superadministrador (ADR 0126): además del token de la plataforma, cada
| operador tiene su cuenta (correo y contraseña) y confirma cada entrada con un código
| que le llega por correo.
| - `administradores_plataforma`: los operadores (contraseña con hash).
| - `desafios_plataforma`: el código por correo de cada intento (solo su huella), con
|   vencimiento e intentos contados.
| - `sesiones_plataforma`: las sesiones abiertas (solo la huella del token), con
|   vencimiento; salir las cierra.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administradores_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('nombre');
            $tabla->string('email')->unique();
            $tabla->string('password');
            $tabla->boolean('activo')->default(true);
            $tabla->timestamp('ultimo_acceso_en')->nullable();
            $tabla->timestamps();
        });
        Schema::create('desafios_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('administrador_id')->constrained('administradores_plataforma')->cascadeOnDelete();
            $tabla->string('codigo_hash', 64);
            $tabla->unsignedTinyInteger('intentos')->default(0);
            $tabla->timestamp('expira_en');
            $tabla->timestamp('usado_en')->nullable();
            $tabla->string('ip', 45)->nullable();
            $tabla->timestamps();
        });
        Schema::create('sesiones_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('administrador_id')->constrained('administradores_plataforma')->cascadeOnDelete();
            $tabla->string('token_hash', 64)->unique();
            $tabla->timestamp('expira_en');
            $tabla->timestamp('ultimo_uso_en')->nullable();
            $tabla->timestamp('cerrada_en')->nullable();
            $tabla->string('ip', 45)->nullable();
            $tabla->string('agente', 255)->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_plataforma');
        Schema::dropIfExists('desafios_plataforma');
        Schema::dropIfExists('administradores_plataforma');
    }
};
