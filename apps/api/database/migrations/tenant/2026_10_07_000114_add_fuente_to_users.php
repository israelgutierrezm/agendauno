<?php

declare(strict_types=1);

use App\Modules\Tenancy\CatalogoFuentes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): el TIPO DE LETRA que eligió cada usuario en Apariencia
 * (clave de {@see CatalogoFuentes}); null = el predeterminado. Preferencia personal,
 * como el tema: se guarda en la cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->string('fuente', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn('fuente');
        });
    }
};
