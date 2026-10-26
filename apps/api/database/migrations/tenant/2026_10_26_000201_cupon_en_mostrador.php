<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Un cupón en una venta del mostrador (ADR 0220): cuánto descontó y cuál fue. El
| descuento ya va repartido en el subtotal de cada línea (así una devolución regresa
| lo que de verdad se pagó); aquí queda para el ticket y los reportes.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas_pos', function (Blueprint $t): void {
            $t->unsignedBigInteger('descuento_minor')->default(0);
            $t->foreignId('promocion_id')->nullable()->constrained('promociones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ventas_pos', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('promocion_id');
            $t->dropColumn('descuento_minor');
        });
    }
};
