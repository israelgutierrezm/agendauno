<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Perfil público del negocio, junto a su logo: la descripción larga, la portada y
| sus redes y sitio web (Instagram, Facebook, TikTok, YouTube). Se muestran en su
| página pública y en su página de enlaces (la del link de Instagram).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->text('descripcion')->nullable();
            $tabla->string('portada_url', 2048)->nullable();
            // {instagram, facebook, tiktok, youtube, sitio_web}: enlaces ya normalizados.
            $tabla->json('redes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn(['descripcion', 'portada_url', 'redes']);
        });
    }
};
