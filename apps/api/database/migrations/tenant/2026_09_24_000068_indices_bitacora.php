<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Bitácora filtrable por persona del equipo y por tipo de movimiento en el tiempo.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('auditorias', function (Blueprint $tabla): void {
            $tabla->index(['actor_id', 'created_at']);
            $tabla->index(['accion', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('auditorias', function (Blueprint $tabla): void {
            $tabla->dropIndex(['actor_id', 'created_at']);
            $tabla->dropIndex(['accion', 'created_at']);
        });
    }
};
