<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('importaciones_agenda', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('modo', 16);
            $tabla->string('referencia', 120);
            $tabla->string('huella', 64);
            $tabla->ulid('lote');
            $tabla->json('sesiones');
            $tabla->string('serie_ulid', 26)->nullable();
            $tabla->timestamp('created_at');
            $tabla->unique(['modo', 'referencia']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('importaciones_agenda');
    }
};
