<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Se retira la integración con las reservas desde Google Maps: se quita la columna que
| decía desde cuándo aparecía cada negocio (la creó una migración que ya no existe;
| una base nueva no la tiene).
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('estudios', 'en_reserva_google_desde')) {
            Schema::table('estudios', function (Blueprint $t): void {
                $t->dropColumn('en_reserva_google_desde');
            });
        }
    }

    public function down(): void
    {
        // No vuelve: la integración se retiró.
    }
};
