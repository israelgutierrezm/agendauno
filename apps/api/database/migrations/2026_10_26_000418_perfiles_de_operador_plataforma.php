<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| El perfil de cada operador del superadmin (ADR 0215): `total` (todo), `cobranza`
| (la renta), `soporte` (ayudar a los negocios) o `lectura` (solo ver). Los de antes
| quedan con `total`, como hasta hoy.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administradores_plataforma', function (Blueprint $t): void {
            $t->string('perfil', 20)->default('total');
        });
    }

    public function down(): void
    {
        Schema::table('administradores_plataforma', function (Blueprint $t): void {
            $t->dropColumn('perfil');
        });
    }
};
