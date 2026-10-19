<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| El enlace de activación vence (ADR 0169): hasta cuándo sirve el token. Los enlaces
| que ya estaban enviados sirven 7 días más (el valor por omisión del parámetro
| `cuentas.dias_activar_cuenta`); después se pide uno nuevo.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->timestamp('activation_expira_en')->nullable();
        });

        DB::connection('tenant')->table('users')
            ->whereNotNull('activation_token')
            ->update(['activation_expira_en' => now()->addDays(7)]);
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn('activation_expira_en');
        });
    }
};
