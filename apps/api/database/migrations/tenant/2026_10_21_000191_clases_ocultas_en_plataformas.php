<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Wellhub no borra una clase: se oculta (`visible: false`) con todos sus horarios (ADR
| 0202). `oculta_en` dice desde cuándo quedó oculta la clase de una publicación que se
| dejó de publicar; al volver a publicarla se muestra de nuevo.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('publicaciones_plataforma', function (Blueprint $t): void {
            $t->timestamp('oculta_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('publicaciones_plataforma', function (Blueprint $t): void {
            $t->dropColumn('oculta_en');
        });
    }
};
