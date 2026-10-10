<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captación del negocio (plan de mejoras B01, ADR 0117): quien todavía no es miembro deja
 * sus datos en un formulario (del sitio, por enlace, QR o embebido en otra página) y llega
 * a Prospectos, separado de Miembros. Un prospecto no es una ficha: no cuenta como alumno
 * activo ni reserva; el negocio lo convierte en miembro con la invitación de siempre.
 *
 * Retoma la idea de las tablas `prospectos` que se quitaron el 2026-09-16 (migración
 * 000038), ahora como captación separada de Miembros, a pedido del dueño.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('formularios_captacion', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            // Interno (para el equipo) y lo que ve quien lo llena.
            $tabla->string('nombre', 120);
            $tabla->string('titulo', 160);
            $tabla->string('descripcion', 1000)->nullable();
            $tabla->string('mensaje_confirmacion', 500)->nullable();
            $tabla->boolean('pide_telefono')->default(true);
            $tabla->boolean('telefono_obligatorio')->default(false);
            // El de «Pedir acceso» del sitio del negocio (uno a la vez).
            $tabla->boolean('predeterminado')->default(false);
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();
        });

        Schema::connection('tenant')->create('campos_formulario_captacion', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('formulario_id')->constrained('formularios_captacion')->cascadeOnDelete();
            $tabla->string('etiqueta', 160);
            $tabla->string('tipo', 20);
            $tabla->boolean('obligatorio')->default(false);
            $tabla->json('opciones')->nullable();
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->timestamps();
        });

        Schema::connection('tenant')->create('prospectos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('nombre', 160);
            $tabla->string('email', 190)->nullable();
            $tabla->string('telefono', 40)->nullable();
            // Qué le interesa (lo que tenía elegido al pedir acceso).
            $tabla->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $tabla->foreignId('actividad_id')->nullable()->constrained('actividades')->nullOnDelete();
            $tabla->foreignId('sesion_id')->nullable()->constrained('sesiones')->nullOnDelete();
            $tabla->foreignId('producto_id')->nullable()->constrained('productos_comerciales')->nullOnDelete();
            $tabla->foreignId('formulario_id')->nullable()->constrained('formularios_captacion')->nullOnDelete();
            $tabla->string('origen', 20);
            $tabla->string('fuente', 120)->nullable();
            $tabla->string('estado', 30);
            $tabla->string('motivo_cierre', 30)->nullable();
            $tabla->string('comentario_cierre', 300)->nullable();
            $tabla->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamp('proximo_seguimiento_en')->nullable();
            $tabla->timestamp('primer_contacto_en')->nullable();
            // La ficha cuando se convierte (o la que ya existía, si el equipo la eligió).
            $tabla->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $tabla->timestamp('convertido_en')->nullable();
            // Lo que respondió, con la etiqueta de entonces (el formulario puede cambiar).
            $tabla->json('respuestas')->nullable();
            $tabla->unsignedInteger('aviso_version')->nullable();
            $tabla->timestamp('acepto_aviso_en')->nullable();
            $tabla->timestamps();

            $tabla->index(['estado', 'created_at']);
            $tabla->index('email');
            $tabla->index('telefono');
        });

        Schema::connection('tenant')->create('prospecto_eventos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('prospecto_id')->constrained('prospectos')->cascadeOnDelete();
            $tabla->string('tipo', 30);
            $tabla->json('detalle')->nullable();
            $tabla->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamp('created_at')->nullable();
            $tabla->index(['prospecto_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('prospecto_eventos');
        Schema::connection('tenant')->dropIfExists('prospectos');
        Schema::connection('tenant')->dropIfExists('campos_formulario_captacion');
        Schema::connection('tenant')->dropIfExists('formularios_captacion');
    }
};
