<?php

declare(strict_types=1);

use App\Modules\Tenancy\CatalogoTemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): APARIENCIA por usuario. `tema` = clave del catálogo
 * de temas ({@see CatalogoTemas}); null = el predeterminado.
 * `tema_personalizacion` = ajustes propios de color sobre ese tema (solo si el tema
 * los admite). Es una preferencia personal: se guarda en la cuenta y viaja con ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->string('tema', 40)->nullable();
            $tabla->json('tema_personalizacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn(['tema', 'tema_personalizacion']);
        });
    }
};
