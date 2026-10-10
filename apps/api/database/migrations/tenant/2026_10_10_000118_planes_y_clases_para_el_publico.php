<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que necesita quien todavía no conoce el negocio para decidir (plan de mejoras M03,
 * B03 y C03), validado por el propio negocio:
 * - un plan: qué incluye en una línea, si es el destacado y si es «para tu primera vez»
 *   (solo lo compra quien nunca vino; lo valida el servidor);
 * - una clase: si es para principiantes, qué se necesita saber y qué llevar;
 * - un instructor: su semblanza.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->string('descripcion', 300)->nullable();
            $tabla->boolean('destacado')->default(false);
            $tabla->boolean('primera_vez')->default(false);
        });
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->boolean('para_principiantes')->default(false);
            $tabla->string('requisitos', 300)->nullable();
            $tabla->string('que_llevar', 300)->nullable();
        });
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->string('semblanza', 600)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->dropColumn(['descripcion', 'destacado', 'primera_vez']);
        });
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->dropColumn(['para_principiantes', 'requisitos', 'que_llevar']);
        });
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn('semblanza');
        });
    }
};
