<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Rol activo: quien tiene varios roles entra con uno y el servidor solo le concede
| los permisos de ese rol.
| - personal_access_tokens.rol_activo: el rol de ESA sesión (cada dispositivo el suyo).
| - users.ultimo_rol: el que eligió la última vez (viene marcado al volver a entrar).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('personal_access_tokens', function (Blueprint $tabla): void {
            $tabla->string('rol_activo', 40)->nullable()->after('name');
        });
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->string('ultimo_rol', 40)->nullable()->after('roles');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('personal_access_tokens', function (Blueprint $tabla): void {
            $tabla->dropColumn('rol_activo');
        });
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn('ultimo_rol');
        });
    }
};
