<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Registro de alumno con un correo que ya es de alguien en el negocio (una ficha o una
| cuenta dada de baja): queda pendiente hasta que confirma el correo. Solo el hash del
| token y la contraseña ya cifrada; vence y sirve una vez.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('verificaciones_registro', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('email');
            $tabla->string('nombre');
            $tabla->string('primer_apellido')->nullable();
            $tabla->string('password');
            $tabla->string('token_hash', 64);
            $tabla->timestamp('expira_en');
            $tabla->timestamp('usada_en')->nullable();
            $tabla->timestamps();

            $tabla->index(['email', 'usada_en']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('verificaciones_registro');
    }
};
