<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control plane: cargos de renta del SaaS (la suscripción que AgendaUno cobra a cada
 * estudio por periodo). Se genera uno por (estudio, periodo) con el monto según el modo
 * de cobro (activos × precio, o cuota fija) congelado al cerrar el periodo. El dueño lo
 * ve en su apartado de renta y lo paga por la pasarela de la plataforma (fase posterior).
 *
 * - `monto_minor`: dinero en unidades menores (nunca float).
 * - `alumnos_activos`: evidencia del cálculo cuando el modo es por activos.
 * - unique(estudio_id, periodo): un solo cargo por periodo (generación idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargos_renta', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            $tabla->string('periodo', 7); // YYYY-MM
            $tabla->string('modo_cobro');
            $tabla->unsignedInteger('alumnos_activos')->default(0);
            $tabla->unsignedBigInteger('monto_minor');
            $tabla->char('moneda', 3)->default('MXN');
            $tabla->string('estado')->default('pendiente')->index();
            $tabla->date('vence_en')->nullable();
            $tabla->timestamp('pagado_en')->nullable();
            $tabla->string('metodo_pago')->nullable();
            $tabla->string('referencia_pago')->nullable();
            $tabla->timestamps();

            $tabla->unique(['estudio_id', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargos_renta');
    }
};
