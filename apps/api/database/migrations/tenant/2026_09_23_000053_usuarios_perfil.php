<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Perfil del usuario: nombre y apellidos por separado (para mostrar "primer nombre +
| apellido paterno" sin adivinar) y su foto. `name` sigue siendo el nombre completo.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->string('nombre', 80)->nullable();
            $tabla->string('primer_apellido', 80)->nullable();
            $tabla->string('segundo_apellido', 80)->nullable();
            $tabla->string('foto_ruta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn(['nombre', 'primer_apellido', 'segundo_apellido', 'foto_ruta']);
        });
    }
};
