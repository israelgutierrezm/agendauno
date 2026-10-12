<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| El SEO del sitio publicado de cada negocio (ADR 0146, fase 2): el título, la
| descripción y la imagen de Inicio y de cada página propia, con su dirección. Se copia
| aquí al publicar para que la cabecera que ven buscadores y redes (y el sitemap) no
| tenga que abrir la base del negocio.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->json('seo_sitio')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn('seo_sitio');
        });
    }
};
