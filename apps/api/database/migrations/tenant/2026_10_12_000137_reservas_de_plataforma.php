<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Reservas desde la app de Wellhub y TotalPass (ADR 0149). El negocio publica en la
| plataforma las clases que elige, de una sucursal, y recibe sus reservas: la persona
| sigue siendo visitante de la plataforma y su reserva, un lugar de plataforma.
|
| - `integraciones`: si publica sus clases y de qué sucursal (cuántos días por adelantado
|   y cuántos lugares en las clases sin cupo son parámetros del negocio).
| - `publicaciones_plataforma`: cada clase (oferta) que se publica, con el producto o
|   plan de la plataforma y su id allá.
| - `horarios_plataforma`: cada sesión publicada, con su id en la plataforma y lo último
|   que se le mandó (para no repetir llamadas y saber qué cambió).
| - `lugares_plataforma`: por qué se rechazó una reserva (lo que se le contestó a la
|   plataforma), para contestar lo mismo si vuelve a preguntar.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('integraciones', function (Blueprint $tabla): void {
            $tabla->boolean('reservas_activas')->default(false);
            $tabla->unsignedBigInteger('sucursal_id')->nullable();
            $tabla->timestamp('reservas_registradas_en')->nullable();
        });

        Schema::connection('tenant')->create('publicaciones_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('proveedor', 20);
            $tabla->foreignId('oferta_id')->constrained('ofertas')->cascadeOnDelete();
            $tabla->string('producto_externo', 40)->nullable();
            $tabla->string('clase_externa', 60)->nullable();
            $tabla->boolean('activa')->default(true);
            $tabla->string('error', 300)->nullable();
            $tabla->timestamps();

            $tabla->unique(['proveedor', 'oferta_id']);
        });

        Schema::connection('tenant')->create('horarios_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('proveedor', 20);
            $tabla->foreignId('sesion_id')->constrained('sesiones')->cascadeOnDelete();
            $tabla->foreignId('publicacion_id')->constrained('publicaciones_plataforma')->cascadeOnDelete();
            $tabla->string('id_externo', 80)->nullable();
            $tabla->string('evento_externo', 80)->nullable();
            $tabla->string('firma', 64)->nullable();
            $tabla->unsignedInteger('capacidad_publicada')->nullable();
            $tabla->unsignedInteger('reservados_publicados')->nullable();
            $tabla->string('estado', 20);
            $tabla->string('error', 300)->nullable();
            $tabla->timestamp('sincronizado_en')->nullable();
            $tabla->timestamps();

            $tabla->unique(['proveedor', 'sesion_id']);
            $tabla->index(['proveedor', 'id_externo']);
            $tabla->index(['proveedor', 'evento_externo']);
            $tabla->index(['proveedor', 'estado']);
        });

        Schema::connection('tenant')->table('lugares_plataforma', function (Blueprint $tabla): void {
            $tabla->string('motivo', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('lugares_plataforma', function (Blueprint $tabla): void {
            $tabla->dropColumn('motivo');
        });
        Schema::connection('tenant')->dropIfExists('horarios_plataforma');
        Schema::connection('tenant')->dropIfExists('publicaciones_plataforma');
        Schema::connection('tenant')->table('integraciones', function (Blueprint $tabla): void {
            $tabla->dropColumn(['reservas_activas', 'sucursal_id', 'reservas_registradas_en']);
        });
    }
};
