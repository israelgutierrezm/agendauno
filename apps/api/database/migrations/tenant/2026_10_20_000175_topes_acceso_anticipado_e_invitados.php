<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Planes «socio» (comparativo del 2026-10-20; ADR 0182): tope de reservas por día y por
| semana, acceso anticipado (reservar antes que los demás) y pases de invitado al mes.
| El plan los define y cada venta los congela en su derecho: editar el plan no cambia
| lo vendido. El invitado no es una persona del negocio: ocupa un lugar en la clase a
| nombre de quien lo invita (como los lugares de Wellhub y TotalPass, ADR 0148).
*/
return new class extends Migration
{
    /** @var list<string> */
    private const REGLAS = ['max_reservas_dia', 'max_reservas_semana', 'acceso_anticipado_horas', 'invitados_por_mes'];

    public function up(): void
    {
        foreach (['productos_comerciales', 'derechos'] as $tabla) {
            Schema::connection('tenant')->table($tabla, function (Blueprint $t): void {
                $t->unsignedSmallInteger('max_reservas_dia')->nullable();
                $t->unsignedSmallInteger('max_reservas_semana')->nullable();
                $t->unsignedSmallInteger('acceso_anticipado_horas')->nullable();
                $t->unsignedTinyInteger('invitados_por_mes')->nullable();
            });
        }

        Schema::connection('tenant')->create('invitados_sesion', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('sesion_id')->constrained('sesiones')->cascadeOnDelete();
            $t->foreignId('anfitrion_id')->constrained('personas')->cascadeOnDelete();
            $t->foreignId('derecho_id')->nullable()->constrained('derechos')->nullOnDelete();
            $t->string('nombre', 120);
            $t->string('estado', 20)->default('reservado');
            $t->timestamp('cancelado_en')->nullable();
            $t->timestamp('asistencia_en')->nullable();
            $t->timestamps();

            $t->index(['sesion_id', 'estado']);
            $t->index(['derecho_id', 'estado']);
            $t->index(['anfitrion_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('invitados_sesion');
        foreach (['productos_comerciales', 'derechos'] as $tabla) {
            Schema::connection('tenant')->table($tabla, function (Blueprint $t): void {
                $t->dropColumn(self::REGLAS);
            });
        }
    }
};
