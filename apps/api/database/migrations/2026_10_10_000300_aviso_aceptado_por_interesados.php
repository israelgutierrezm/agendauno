<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Qué versión del aviso de privacidad aceptó cada interesado (ADR 0108), como el
| registro de negocios: la lista de interesados también capta datos personales.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interesados', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('aviso_version')->nullable()->after('acepto_aviso_en');
        });
    }

    public function down(): void
    {
        Schema::table('interesados', function (Blueprint $tabla): void {
            $tabla->dropColumn('aviso_version');
        });
    }
};
