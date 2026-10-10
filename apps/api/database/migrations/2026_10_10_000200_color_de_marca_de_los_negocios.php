<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| El color de la marca de cada negocio (ADR 0110): la barra de su app instalada (PWA) y
| su página. Hexadecimal (#RRGGBB); sin él, el del producto.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->char('color_marca', 7)->nullable()->after('portada_url');
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn('color_marca');
        });
    }
};
