<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Bajas lógicas de usuarios y personas: dar de baja no borra. `deleted_at` las oculta
| en todo el sistema (con su historial intacto) y `eliminado_por` guarda quién lo
| hizo; el detalle y el motivo quedan en la bitácora. El correo sigue siendo único:
| quien vuelve con ese correo se reactiva en lugar de duplicarse.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->softDeletes();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
            $tabla->index('deleted_at');
        });

        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->softDeletes();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
            $tabla->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->dropIndex(['deleted_at']);
            $tabla->dropColumn(['deleted_at', 'eliminado_por']);
        });

        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->dropIndex(['deleted_at']);
            $tabla->dropColumn(['deleted_at', 'eliminado_por']);
        });
    }
};
