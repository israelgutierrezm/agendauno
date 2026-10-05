<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha de nacimiento y género de una persona: opcionales, los anota el negocio o la
 * propia persona en «Mi perfil». El género es una lista breve (GeneroPersona).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->date('fecha_nacimiento')->nullable()->after('celular');
            $tabla->string('genero', 20)->nullable()->after('fecha_nacimiento');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->dropColumn(['fecha_nacimiento', 'genero']);
        });
    }
};
