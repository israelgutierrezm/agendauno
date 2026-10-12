<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Widgets para la web del negocio (ADR 0141): parte de su página (el calendario de
| clases o sus planes) dentro de su propio sitio. Cada uno con su tipo, sus filtros
| iniciales, su tema y color de acento, y las webs donde se puede mostrar.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('widgets', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('tipo', 20);
            $tabla->string('nombre', 120);
            $tabla->json('filtros')->nullable();
            $tabla->string('tema', 10)->default('claro');
            $tabla->char('acento', 7)->nullable();
            $tabla->json('dominios')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('widgets');
    }
};
