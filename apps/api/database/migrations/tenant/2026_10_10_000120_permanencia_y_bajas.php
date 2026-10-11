<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanencia y baja anticipada (plan de mejoras B04, decisiones del dueño del
 * 2026-10-10):
 *
 * - Un plan (membresía) puede pedir una permanencia mínima en meses y decir qué pasa si
 *   se da de baja antes: sin penalización, un monto fijo o las mensualidades que falten.
 * - La línea de orden guarda las condiciones que el cliente aceptó (texto, versión,
 *   cuándo y cómo); el acuerdo las congela: editar el plan después no cambia lo pactado.
 * - La baja de una membresía queda registrada con su cálculo (fecha efectiva, meses que
 *   faltaban, precio usado, penalización, adeudo) y quién la hizo.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->unsignedSmallInteger('permanencia_meses')->nullable();
            // sin_penalizacion | monto_fijo | meses_restantes
            $tabla->string('baja_anticipada', 20)->nullable();
            $tabla->unsignedBigInteger('penalizacion_minor')->nullable();
        });
        Schema::connection('tenant')->table('lineas_orden', function (Blueprint $tabla): void {
            $tabla->json('condiciones')->nullable();
        });
        Schema::connection('tenant')->table('acuerdos', function (Blueprint $tabla): void {
            $tabla->date('permanencia_hasta')->nullable();
            $tabla->string('baja_anticipada', 20)->nullable();
            $tabla->unsignedBigInteger('penalizacion_minor')->nullable();
            $tabla->json('condiciones')->nullable();
            // Su último día (la baja aplica al terminar el periodo pagado).
            $tabla->date('baja_efectiva_el')->nullable();
        });
        Schema::connection('tenant')->create('bajas_acuerdo', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('acuerdo_id')->constrained('acuerdos')->cascadeOnDelete();
            $tabla->date('efectiva_el');
            $tabla->boolean('en_permanencia')->default(false);
            $tabla->string('modo', 20)->nullable();
            $tabla->unsignedSmallInteger('meses_restantes')->default(0);
            $tabla->unsignedBigInteger('precio_base_minor')->nullable();
            $tabla->unsignedBigInteger('penalizacion_minor')->default(0);
            $tabla->boolean('penalizacion_exenta')->default(false);
            $tabla->unsignedBigInteger('adeudo_minor')->default(0);
            $tabla->boolean('adeudo_conservado')->default(false);
            $tabla->foreignId('orden_id')->nullable()->constrained('ordenes')->nullOnDelete();
            $tabla->string('motivo', 300)->nullable();
            $tabla->unsignedBigInteger('usuario_id')->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('bajas_acuerdo');
        Schema::connection('tenant')->table('acuerdos', function (Blueprint $tabla): void {
            $tabla->dropColumn(['permanencia_hasta', 'baja_anticipada', 'penalizacion_minor', 'condiciones', 'baja_efectiva_el']);
        });
        Schema::connection('tenant')->table('lineas_orden', function (Blueprint $tabla): void {
            $tabla->dropColumn('condiciones');
        });
        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->dropColumn(['permanencia_meses', 'baja_anticipada', 'penalizacion_minor']);
        });
    }
};
