<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Bitácora del superadministrador (ADR 0132): qué operador hizo qué, a qué negocio,
| con qué datos (sin secretos), desde dónde y con qué resultado. Y un identificador
| público para cada sesión de operador, para cerrarla desde el apartado «Equipo».
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            // Null: con el token directo (fuera de producción) o una entrada fallida.
            $tabla->foreignId('administrador_id')->nullable()->constrained('administradores_plataforma')->nullOnDelete();
            $tabla->string('accion', 120)->index();
            $tabla->string('metodo', 10);
            $tabla->string('ruta', 255);
            $tabla->foreignId('estudio_id')->nullable()->constrained('estudios')->nullOnDelete();
            $tabla->json('datos')->nullable();
            $tabla->unsignedSmallInteger('estado');
            $tabla->string('ip', 45)->nullable();
            $tabla->timestamp('created_at')->useCurrent();
            $tabla->index(['estudio_id', 'created_at']);
            $tabla->index(['administrador_id', 'created_at']);
        });

        Schema::table('sesiones_plataforma', function (Blueprint $tabla): void {
            $tabla->ulid('ulid')->nullable()->unique()->after('id');
        });
        DB::table('sesiones_plataforma')->whereNull('ulid')->orderBy('id')->each(function (object $fila): void {
            DB::table('sesiones_plataforma')->where('id', $fila->id)->update(['ulid' => strtolower((string) Str::ulid())]);
        });
    }

    public function down(): void
    {
        Schema::table('sesiones_plataforma', function (Blueprint $tabla): void {
            $tabla->dropUnique(['ulid']);
            $tabla->dropColumn('ulid');
        });
        Schema::dropIfExists('bitacora_plataforma');
    }
};
