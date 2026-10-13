<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Visitantes de Wellhub y TotalPass (ADR 0148). Quien llega por una plataforma de
| bienestar es cliente de la plataforma, no del negocio: no es persona, alumno ni
| miembro hasta que el negocio lo registre por la vía de siempre.
|
| - `visitantes_plataforma`: quién es, como lo dice la plataforma (su id, nombre,
|   correo y teléfono si los manda) y sus visitas.
| - `lugares_plataforma`: el lugar que ocupa en una clase (por su visita o, después, por
|   su reserva desde la app de la plataforma). Ocupa cupo sin ser una reserva.
| - `eventos_plataforma`: los avisos que mandan las plataformas (bandeja). Se guardan al
|   recibirlos y se procesan aparte: Wellhub espera respuesta en un segundo.
| - `checkins`: la visita puede llegar sin clase (la persona hizo check-in en la app y
|   no había una sola clase a esa hora), con su visitante y si fue automática.
| - `integraciones`: el token de la dirección donde avisa la plataforma y si ya quedó
|   registrada con ella.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('visitantes_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('proveedor', 20);
            $tabla->string('id_externo', 80);
            $tabla->string('nombre', 160)->nullable();
            $tabla->string('email', 190)->nullable();
            $tabla->string('telefono', 40)->nullable();
            $tabla->string('producto', 160)->nullable();
            $tabla->timestamp('primera_visita_en')->nullable();
            $tabla->timestamp('ultima_visita_en')->nullable();
            $tabla->timestamps();

            $tabla->unique(['proveedor', 'id_externo']);
            $tabla->index('email');
            $tabla->index('ultima_visita_en');
        });

        Schema::connection('tenant')->create('lugares_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('sesion_id')->constrained('sesiones')->cascadeOnDelete();
            $tabla->string('proveedor', 20);
            $tabla->foreignId('visitante_id')->nullable()->constrained('visitantes_plataforma')->nullOnDelete();
            $tabla->string('origen', 20);
            $tabla->string('estado', 20);
            $tabla->string('referencia_externa', 120)->nullable()->unique();
            $tabla->unsignedBigInteger('checkin_id')->nullable()->unique();
            $tabla->timestamps();

            $tabla->index(['sesion_id', 'estado']);
            $tabla->index(['visitante_id', 'sesion_id']);
        });

        Schema::connection('tenant')->create('eventos_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('proveedor', 20);
            $tabla->string('tipo', 60);
            $tabla->string('clave', 120);
            $tabla->json('payload');
            $tabla->string('estado', 20)->default('pendiente');
            $tabla->string('resultado', 300)->nullable();
            $tabla->unsignedInteger('intentos')->default(0);
            $tabla->timestamp('recibido_en');
            $tabla->timestamp('procesado_en')->nullable();
            $tabla->timestamps();

            $tabla->unique(['proveedor', 'clave']);
            $tabla->index(['estado', 'id']);
        });

        Schema::connection('tenant')->table('checkins', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('sesion_id')->nullable()->change();
            $tabla->unsignedBigInteger('visitante_id')->nullable();
            $tabla->string('origen', 20)->default('manual');

            $tabla->index('visitante_id');
            $tabla->index(['sesion_id', 'registrado_en']);
        });

        Schema::connection('tenant')->table('integraciones', function (Blueprint $tabla): void {
            $tabla->string('token_webhook', 64)->nullable();
            $tabla->timestamp('webhook_registrado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('integraciones', function (Blueprint $tabla): void {
            $tabla->dropColumn(['token_webhook', 'webhook_registrado_en']);
        });
        Schema::connection('tenant')->table('checkins', function (Blueprint $tabla): void {
            $tabla->dropIndex(['visitante_id']);
            $tabla->dropIndex(['sesion_id', 'registrado_en']);
            $tabla->dropColumn(['visitante_id', 'origen']);
        });
        Schema::connection('tenant')->dropIfExists('eventos_plataforma');
        Schema::connection('tenant')->dropIfExists('lugares_plataforma');
        Schema::connection('tenant')->dropIfExists('visitantes_plataforma');
    }
};
