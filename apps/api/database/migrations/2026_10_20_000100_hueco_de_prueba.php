<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Extender una prueba que ya había terminado regala solo los días de la extensión: los
| que corrieron entre el fin de la prueba y la extensión se cobran. Se guarda ese hueco
| (cuándo terminó la prueba anterior y desde cuándo corre la extensión).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->date('prueba_termino_antes')->nullable();
            $tabla->date('prueba_extendida_desde')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn(['prueba_termino_antes', 'prueba_extendida_desde']);
        });
    }
};
