<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Quien conectó su calendario antes de pedir el permiso de avisos (ADR 0199): Google o
| Microsoft contestan 401/403 al abrir el canal. `aviso_falta_permiso` lo recuerda para
| pedirle en Mi perfil que vuelva a conectarlo; mientras, sigue la pasada de cada 10
| minutos.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('calendarios_externos', function (Blueprint $t): void {
            $t->boolean('aviso_falta_permiso')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('calendarios_externos', function (Blueprint $t): void {
            $t->dropColumn('aviso_falta_permiso');
        });
    }
};
