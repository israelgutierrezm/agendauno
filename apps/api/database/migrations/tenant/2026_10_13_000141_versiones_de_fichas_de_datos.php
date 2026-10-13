<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fichas de datos editables sin perder lo respondido (ADR 0150): la ficha lleva su
| versión y se archiva en vez de borrarse; una pregunta quitada de una ficha con
| respuestas se archiva; y cada respuesta guarda la versión y las preguntas con que se
| contestó, para leerla igual aunque la ficha cambie después.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('formularios', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('version')->default(1);
            $tabla->timestamp('archivado_en')->nullable();
        });
        Schema::connection('tenant')->table('campos_formulario', function (Blueprint $tabla): void {
            $tabla->timestamp('archivado_en')->nullable();
        });
        Schema::connection('tenant')->table('respuestas_formulario', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('version')->default(1);
            // Las preguntas de entonces: [{id, etiqueta, tipo, opciones}].
            $tabla->json('campos')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('respuestas_formulario', function (Blueprint $tabla): void {
            $tabla->dropColumn(['version', 'campos']);
        });
        Schema::connection('tenant')->table('campos_formulario', function (Blueprint $tabla): void {
            $tabla->dropColumn('archivado_en');
        });
        Schema::connection('tenant')->table('formularios', function (Blueprint $tabla): void {
            $tabla->dropColumn(['version', 'archivado_en']);
        });
    }
};
