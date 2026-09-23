<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Control plane: cobro del SaaS POR MODALIDAD (ADR 0019).
 *
 * - `tarifas_saas`: tarifario VERSIONADO por modalidad. Un cambio de precios crea una
 *   versión nueva; los cargos guardan con qué versión se calcularon. Se siembran las
 *   tarifas v1 acordadas (MXN, sin IVA): clases por bandas de alumnos activos y citas
 *   por profesional activo con personas incluidas fuera de cita.
 * - `mediciones_uso`: qué métrica se midió (`alumnos_activos` | `profesionales_activos`)
 *   y su detalle (p. ej. equivalentes de tiempo completo).
 * - `cargos_renta`: métrica, versión de tarifa y desglose del cargo.
 * - `estudios.estado_facturacion`: el default `trialing` no existía en el enum (`trial`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarifas_saas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('modalidad', 16);
            $tabla->unsignedInteger('version');
            $tabla->json('definicion');
            $tabla->timestamp('vigente_desde');
            $tabla->timestamps();

            $tabla->unique(['modalidad', 'version']);
        });

        $ahora = now();
        DB::table('tarifas_saas')->insert([
            [
                'ulid' => (string) Str::ulid(),
                'modalidad' => 'clases',
                'version' => 1,
                'definicion' => json_encode([
                    'dias_prueba' => 30,
                    'iva_porcentaje' => 16,
                    // Precio mensual por banda de alumnos activos; la última (sin tope) es el techo.
                    'bandas' => [
                        ['hasta' => 40, 'monto_minor' => 33900],
                        ['hasta' => 80, 'monto_minor' => 63900],
                        ['hasta' => 120, 'monto_minor' => 90900],
                        ['hasta' => 200, 'monto_minor' => 135900],
                        ['hasta' => 300, 'monto_minor' => 178900],
                        ['hasta' => 500, 'monto_minor' => 264900],
                        ['hasta' => null, 'monto_minor' => 288900],
                    ],
                ], JSON_THROW_ON_ERROR),
                'vigente_desde' => $ahora,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'ulid' => (string) Str::ulid(),
                'modalidad' => 'citas',
                'version' => 1,
                'definicion' => json_encode([
                    'dias_prueba' => 14,
                    'iva_porcentaje' => 16,
                    // Precio marginal por profesional activo (tramos acumulativos).
                    'tramos' => [
                        ['hasta' => 1, 'unitario_minor' => 26900],
                        ['hasta' => 2, 'unitario_minor' => 22600],
                        ['hasta' => 10, 'unitario_minor' => 13500],
                        ['hasta' => 20, 'unitario_minor' => 8900],
                        ['hasta' => null, 'unitario_minor' => 0],
                    ],
                    // Regla híbrida: personas atendidas fuera de cita (clases/talleres).
                    'personas_incluidas_por_profesional' => 10,
                    'tope_personas_incluidas' => 100,
                    'extra_por_persona_minor' => 900,
                    // Medio tiempo: menos de estas horas de atención a la semana = 0.5.
                    'horas_medio_tiempo' => 20,
                ], JSON_THROW_ON_ERROR),
                'vigente_desde' => $ahora,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);

        Schema::table('mediciones_uso', function (Blueprint $tabla): void {
            $tabla->string('metrica', 32)->default('alumnos_activos');
            $tabla->json('detalle')->nullable();
        });

        Schema::table('cargos_renta', function (Blueprint $tabla): void {
            $tabla->string('metrica', 32)->nullable();
            $tabla->unsignedInteger('tarifa_version')->nullable();
            $tabla->json('desglose')->nullable();
        });

        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->string('estado_facturacion')->default('trial')->change();
        });
        DB::table('estudios')->where('estado_facturacion', 'trialing')->update(['estado_facturacion' => 'trial']);
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->string('estado_facturacion')->default('trialing')->change();
        });

        Schema::table('cargos_renta', function (Blueprint $tabla): void {
            $tabla->dropColumn(['metrica', 'tarifa_version', 'desglose']);
        });

        Schema::table('mediciones_uso', function (Blueprint $tabla): void {
            $tabla->dropColumn(['metrica', 'detalle']);
        });

        Schema::dropIfExists('tarifas_saas');
    }
};
