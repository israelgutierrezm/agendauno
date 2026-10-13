<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Mostrador: categorías propias del negocio para sus productos (bebidas, ropa,
| suplementos…), para filtrarlos al vender, y la foto de cada producto. Un producto
| tiene a lo más una categoría; quitar una categoría deja sus productos sin ella.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('categorias_articulo', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('nombre', 60);
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->timestamps();
        });

        Schema::connection('tenant')->table('articulos', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('categoria_id')->nullable()->index();
            $tabla->string('foto_ruta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('articulos', function (Blueprint $tabla): void {
            $tabla->dropIndex(['categoria_id']);
            $tabla->dropColumn(['categoria_id', 'foto_ruta']);
        });
        Schema::connection('tenant')->dropIfExists('categorias_articulo');
    }
};
