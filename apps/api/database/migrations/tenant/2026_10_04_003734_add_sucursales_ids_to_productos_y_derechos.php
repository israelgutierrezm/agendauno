<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['productos_comerciales', 'derechos'] as $nombre) {
            Schema::connection('tenant')->table($nombre, function (Blueprint $table): void {
                $table->json('sucursales_ids')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['productos_comerciales', 'derechos'] as $nombre) {
            Schema::connection('tenant')->table($nombre, function (Blueprint $table): void {
                $table->dropColumn('sucursales_ids');
            });
        }
    }
};
