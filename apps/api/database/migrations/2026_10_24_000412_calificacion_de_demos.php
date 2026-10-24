<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Lo que cuenta de su negocio quien agenda una demo (ADR 0204): si ya opera, cuántos
| alumnos o clientes tiene o cuándo abre, sus sucursales, qué quiere resolver y su
| Instagram o página. Ventas lo lee antes de la llamada. Son respuestas de un
| formulario comercial, no datos del dominio: van juntas en un JSON.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demos_agendadas', function (Blueprint $t): void {
            $t->json('calificacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('demos_agendadas', function (Blueprint $t): void {
            $t->dropColumn('calificacion');
        });
    }
};
