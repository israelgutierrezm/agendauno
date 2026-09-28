<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Roles propios del negocio: además de los de sistema (dueño, admin, recepción,
| instructor, miembro, definidos en el código), el negocio crea los suyos con los
| permisos que elija (ADR 0057). `clave` es la que se guarda en users.roles y no
| cambia aunque se renombre el rol.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('roles', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('clave', 40)->unique();
            $tabla->string('nombre', 60);
            // Qué parte de la app le toca (hoy los roles propios son del equipo).
            $tabla->string('faceta', 20)->default('equipo');
            $tabla->json('permisos');
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('roles');
    }
};
