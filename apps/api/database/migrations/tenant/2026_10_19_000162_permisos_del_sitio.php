<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Permisos propios del sitio web (ADR 0167): `sitio.editar` (preparar cambios) y
| `sitio.publicar` (publicarlos y abrir o cerrar la página). Antes los daba
| `estudio.gestionar`: los roles propios que lo tienen reciben los dos, para que nadie
| pierda lo que ya hacía. Volver atrás no los quita (no se sabe cuáles se dieron a mano).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $db = DB::connection('tenant');
        foreach ($db->table('roles')->get(['id', 'permisos']) as $rol) {
            $permisos = json_decode((string) $rol->permisos, true);
            if (! is_array($permisos) || ! in_array('estudio.gestionar', $permisos, true)) {
                continue;
            }
            $nuevos = array_values(array_unique([...$permisos, 'sitio.editar', 'sitio.publicar']));
            $db->table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($nuevos)]);
        }
    }

    public function down(): void
    {
        // Sin cambios: ver arriba.
    }
};
