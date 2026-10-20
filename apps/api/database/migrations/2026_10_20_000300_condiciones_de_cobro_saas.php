<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Lo pactado con cada negocio, con fecha de vigencia (decisión del dueño, 2026-10-20;
| ADR 0181): el superadmin dice desde qué mes aplica una cuota fija o el cobro por uso,
| y cada mes se cobra con lo que regía a su cierre. Las columnas de `estudios` siguen
| como lo vigente hoy (y lo que rige si un negocio no tiene versiones).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condiciones_cobro_saas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            // Primer mes (AAAA-MM, en la zona del negocio) en que rige.
            $tabla->char('desde_periodo', 7);
            $tabla->string('modo_cobro', 20);
            $tabla->unsignedBigInteger('cuota_fija_minor')->default(0);
            $tabla->char('cuota_fija_moneda', 3)->default('MXN');
            $tabla->timestamps();

            $tabla->unique(['estudio_id', 'desde_periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('condiciones_cobro_saas');
    }
};
