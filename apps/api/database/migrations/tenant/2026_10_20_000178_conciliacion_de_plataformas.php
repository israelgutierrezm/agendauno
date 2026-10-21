<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Conciliación de lo que pagan Wellhub y TotalPass (ADR 0185). Ninguna de las dos
| publica una API de pagos: el negocio captura cuánto le pagan por visita (con fecha
| de vigencia) e importa el reporte de pagos del portal de la plataforma (CSV). Se
| compara contra sus visitas validadas del mes.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('tarifas_plataforma', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('proveedor', 20);
            $t->unsignedBigInteger('monto_minor');
            $t->char('moneda', 3);
            $t->date('vigente_desde');
            $t->timestamps();

            $t->unique(['proveedor', 'vigente_desde']);
        });

        Schema::connection('tenant')->create('conciliaciones_plataforma', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('proveedor', 20);
            $t->char('periodo', 7);
            $t->string('archivo', 160)->nullable();
            $t->unsignedInteger('lineas')->default(0);
            $t->unsignedBigInteger('recibido_minor')->default(0);
            $t->char('moneda', 3);
            $t->unsignedBigInteger('importado_por')->nullable();
            $t->timestamps();

            $t->index(['proveedor', 'periodo']);
        });

        Schema::connection('tenant')->create('pagos_plataforma', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('conciliacion_id')->constrained('conciliaciones_plataforma')->cascadeOnDelete();
            $t->date('fecha');
            $t->string('referencia', 120)->nullable();
            $t->unsignedBigInteger('monto_minor');
            $t->foreignId('checkin_id')->nullable()->constrained('checkins')->nullOnDelete();

            $t->index(['conciliacion_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('pagos_plataforma');
        Schema::connection('tenant')->dropIfExists('conciliaciones_plataforma');
        Schema::connection('tenant')->dropIfExists('tarifas_plataforma');
    }
};
