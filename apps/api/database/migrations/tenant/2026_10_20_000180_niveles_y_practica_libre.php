<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Requisito de nivel y práctica libre (ADR 0187). Una clase puede pedir un nivel de su
| actividad («requiere Pole Nivel 2»): el cliente la reserva si el negocio le asignó ese
| nivel o uno más alto. Una práctica libre (Open Pole, Open Gym) no lleva instructor y
| su cupo puede salir del equipo del espacio.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $t): void {
            $t->foreignId('nivel_id')->nullable()->constrained('niveles')->nullOnDelete();
            $t->boolean('practica_libre')->default(false);
        });

        Schema::connection('tenant')->create('personas_niveles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $t->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $t->foreignId('nivel_id')->constrained('niveles')->cascadeOnDelete();
            $t->unsignedBigInteger('asignado_por')->nullable();
            $t->timestamps();

            $t->unique(['persona_id', 'actividad_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('personas_niveles');
        Schema::connection('tenant')->table('ofertas', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('nivel_id');
            $t->dropColumn('practica_libre');
        });
    }
};
