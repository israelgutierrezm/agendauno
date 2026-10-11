<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuotas del negocio (plan de mejoras B04): inscripción y anualidad (y, en el contrato,
 * la penalización por baja) como conceptos aparte, que no dan clases.
 *
 * - Una línea de orden puede no tener producto: lleva su `concepto` y, si se generó
 *   sola, una `referencia` única para no cobrarla dos veces (reintentos, el proceso
 *   diario corriendo de nuevo).
 * - Un plan dice si cobra inscripción y si cobra anualidad.
 * - El negocio configura los montos, tras cuántos meses sin plan vuelve a cobrar la
 *   inscripción y cuándo se cobra la anualidad (aniversario o fecha fija).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('lineas_orden', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('producto_comercial_id')->nullable()->change();
            $tabla->string('concepto', 30)->nullable();
            $tabla->string('referencia', 120)->nullable()->unique();
        });
        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->boolean('cobra_inscripcion')->default(false);
            $tabla->boolean('cobra_anualidad')->default(false);
        });
        Schema::connection('tenant')->create('configuracion_cuotas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->unsignedBigInteger('inscripcion_minor')->default(0);
            // Tras cuántos meses sin plan se vuelve a cobrar (reingreso).
            $tabla->unsignedSmallInteger('reingreso_meses')->default(6);
            $tabla->unsignedBigInteger('anualidad_minor')->default(0);
            // `aniversario` (de su alta) o `fecha_fija` (mes y día del negocio).
            $tabla->string('anualidad_modo', 15)->default('aniversario');
            $tabla->unsignedTinyInteger('anualidad_mes')->nullable();
            $tabla->unsignedTinyInteger('anualidad_dia')->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('configuracion_cuotas');
        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->dropColumn(['cobra_inscripcion', 'cobra_anualidad']);
        });
        Schema::connection('tenant')->table('lineas_orden', function (Blueprint $tabla): void {
            $tabla->dropUnique(['referencia']);
            $tabla->dropColumn(['concepto', 'referencia']);
        });
    }
};
