<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Módulos por negocio (ADR 0123): funciones que el superadministrador enciende o apaga
| en cada negocio (`ModuloNegocio`; por ahora, Familias). Apagadas por omisión: la lista
| guarda solo las encendidas.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->json('modulos')->nullable()->after('whatsapp_habilitado');
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn('modulos');
        });
    }
};
