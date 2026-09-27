<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Clases extra (ADR 0050): se compran aparte (su propio acuerdo y su propio derecho,
| nunca editando el paquete) pero se ligan al paquete vigente y vencen con él.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('derechos', function (Blueprint $tabla): void {
            $tabla->foreignId('extra_de_id')->nullable()->after('acuerdo_id')->constrained('derechos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('derechos', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('extra_de_id');
        });
    }
};
