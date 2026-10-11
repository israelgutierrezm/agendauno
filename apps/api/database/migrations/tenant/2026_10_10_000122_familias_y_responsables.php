<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Familias y tutores (plan de mejoras B05, ADR 0123; revierte el ADR 0059). Decisiones del
 * dueño del 2026-10-10:
 *
 * - Un responsable (alumno con cuenta) gestiona a participantes del negocio que pueden no
 *   tener cuenta; cada participante conserva sus planes, créditos y reservas.
 * - El vínculo vale solo con la autorización del responsable: aceptó el texto versionado
 *   del negocio (versión, huella, el texto mismo, cuándo, por dónde e IP). Retirarla
 *   impide nuevas reservas y accesos.
 * - El superadministrador enciende el módulo en el negocio (apagado por omisión); el
 *   negocio puede apagarlo y redacta su texto (si no, el texto base de la plataforma).
 *   Cambiar el texto sube la versión.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('tutelas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('responsable_id')->constrained('personas')->cascadeOnDelete();
            $tabla->foreignId('participante_id')->constrained('personas')->cascadeOnDelete();
            // madre_padre | tutor_legal | familiar | autorizado
            $tabla->string('parentesco', 20);
            // pendiente | activa | retirada
            $tabla->string('estado', 12)->default('pendiente');
            $tabla->unsignedInteger('autorizacion_version')->nullable();
            $tabla->string('autorizacion_huella', 64)->nullable();
            $tabla->text('autorizacion_texto')->nullable();
            $tabla->timestamp('aceptada_en')->nullable();
            // cuenta | mostrador
            $tabla->string('aceptada_via', 12)->nullable();
            $tabla->string('aceptada_ip', 45)->nullable();
            $tabla->unsignedBigInteger('aceptada_por')->nullable();
            $tabla->timestamp('retirada_en')->nullable();
            $tabla->unsignedBigInteger('retirada_por')->nullable();
            $tabla->string('motivo_retiro', 300)->nullable();
            $tabla->unsignedBigInteger('creada_por')->nullable();
            $tabla->timestamps();
            $tabla->unique(['responsable_id', 'participante_id']);
            $tabla->index(['participante_id', 'estado']);
        });

        Schema::connection('tenant')->create('configuracion_familias', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->boolean('activo')->default(true);
            // null = el texto base de la plataforma.
            $tabla->text('texto_autorizacion')->nullable();
            $tabla->unsignedInteger('version')->default(1);
            $tabla->unsignedBigInteger('actualizado_por')->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('configuracion_familias');
        Schema::connection('tenant')->dropIfExists('tutelas');
    }
};
