<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Terminología propia de cada negocio (ADR 0049): los términos que eligió en lugar
| de los de su perfil (p. ej. "Consulta" en vez de "Cita"). null = los del perfil.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->json('terminologia')->nullable()->after('perfil_negocio');
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn('terminologia');
        });
    }
};
