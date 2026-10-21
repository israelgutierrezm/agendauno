<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Clases en línea y videos (ADR 0184): el enlace de una clase en línea (Zoom, Meet…)
| que ve solo quien la reservó, y una biblioteca de videos que el negocio publica para
| quien tiene un plan vigente (todos o solo algunos planes). Solo enlaces: no se sube
| video ni se pega código.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $t): void {
            $t->string('enlace_en_linea', 500)->nullable();
        });

        Schema::connection('tenant')->create('videos', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('titulo', 150);
            $t->string('descripcion', 500)->nullable();
            $t->string('url', 500);
            $t->unsignedSmallInteger('duracion_minutos')->nullable();
            $t->string('categoria', 60)->nullable();
            $t->boolean('publicado')->default(true);
            $t->unsignedInteger('orden')->default(0);
            $t->timestamps();
        });

        // Los planes que lo incluyen; sin ninguno, cualquier plan vigente.
        Schema::connection('tenant')->create('video_producto', function (Blueprint $t): void {
            $t->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $t->foreignId('producto_id')->constrained('productos_comerciales')->cascadeOnDelete();
            $t->primary(['video_id', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('video_producto');
        Schema::connection('tenant')->dropIfExists('videos');
        Schema::connection('tenant')->table('ofertas', function (Blueprint $t): void {
            $t->dropColumn('enlace_en_linea');
        });
    }
};
