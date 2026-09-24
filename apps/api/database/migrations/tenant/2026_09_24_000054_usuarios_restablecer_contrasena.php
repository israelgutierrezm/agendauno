<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Recuperación de contraseña: hash del token del enlace que se envía por correo y
| hasta cuándo sirve (un solo uso; se borra al usarse).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->string('reset_token', 64)->nullable();
            $tabla->timestamp('reset_expira_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn(['reset_token', 'reset_expira_en']);
        });
    }
};
