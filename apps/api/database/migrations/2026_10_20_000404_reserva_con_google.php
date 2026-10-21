<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Reserva con Google (ADR 0198): el negocio decide aparecer. La plataforma genera los
| feeds de sus sedes (integración «redirect» del Actions Center de Google) y avisa a
| Google de las reservas que llegaron desde ahí.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $t): void {
            $t->timestamp('en_reserva_google_desde')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $t): void {
            $t->dropColumn('en_reserva_google_desde');
        });
    }
};
