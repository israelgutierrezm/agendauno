<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas internas del equipo sobre un cliente («Prefiere clases por la tarde»). Son datos
 * personales: salen en su exportación (ARCO) y se borran si pide cancelar sus datos.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('notas_persona', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $tabla->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            $tabla->text('texto');
            $tabla->timestamps();
            $tabla->index(['persona_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('notas_persona');
    }
};
