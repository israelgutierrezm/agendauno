<?php

declare(strict_types=1);

use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\PerfilNegocio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Modalidad guardada de cada negocio (ADR 0104): solo clases o solo citas. Antes se
| deducía del giro en cada lectura; ahora es un dato del negocio y el giro solo da el
| valor inicial al registrarse. Se llena con la deducción de siempre (un giro
| desconocido trabaja con clases, como `general`) y desde entonces es obligatoria.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->string('modalidad', 16)->nullable()->after('perfil_negocio');
        });

        foreach (PerfilNegocio::cases() as $perfil) {
            DB::table('estudios')
                ->where('perfil_negocio', $perfil->value)
                ->update(['modalidad' => ModalidadServicio::paraPerfil($perfil)->value]);
        }
        DB::table('estudios')->whereNull('modalidad')->update(['modalidad' => ModalidadServicio::Clases->value]);

        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->string('modalidad', 16)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn('modalidad');
        });
    }
};
