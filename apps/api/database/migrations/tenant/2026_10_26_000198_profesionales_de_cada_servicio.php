<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Quién da cada servicio en un negocio de citas (ADR 0217): el colorista no hace
| cortes, la fisioterapeuta no da masajes. Sin filas, lo da cualquier profesional
| (como hasta hoy).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oferta_profesionales', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('oferta_id')->constrained('ofertas')->cascadeOnDelete();
            $t->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['oferta_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oferta_profesionales');
    }
};
