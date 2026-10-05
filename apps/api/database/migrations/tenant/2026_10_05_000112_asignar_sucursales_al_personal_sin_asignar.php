<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR 0098: en un negocio con varias sucursales, el personal sin sucursal asignada ya
 * no ve nada. Hasta hoy, sin asignación veía todas; para que nadie pierda lo que hacía,
 * a ese personal se le asignan todas las sucursales (con su rol principal). Propietario,
 * administración y quien solo es cliente no se tocan. Con una sola sucursal no hace
 * falta: todo es de ella.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $db = DB::connection('tenant');
        $sucursales = $db->table('sucursales')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (count($sucursales) <= 1) {
            return;
        }

        $conAsignacion = $db->table('asignaciones_personal')->whereNull('deleted_at')
            ->pluck('usuario_id')->map(fn ($id): int => (int) $id)->unique()->all();
        $usuarios = $db->table('users')->whereNull('deleted_at')
            ->whereNotIn('id', $conAsignacion)->orderBy('id')->get(['id', 'rol', 'roles']);

        foreach ($usuarios as $usuario) {
            $roles = json_decode((string) ($usuario->roles ?? ''), true);
            $roles = is_array($roles) && $roles !== [] ? array_values(array_filter($roles, 'is_string')) : array_filter([(string) $usuario->rol]);
            if (array_intersect(['propietario', 'admin'], $roles) !== []) {
                continue;
            }
            $personal = array_values(array_diff($roles, ['miembro']));
            if ($personal === []) {
                continue;
            }

            foreach ($sucursales as $sucursal) {
                $existente = $db->table('asignaciones_personal')
                    ->where('usuario_id', $usuario->id)->where('sucursal_id', $sucursal)->first(['id']);
                if ($existente !== null) {
                    // Se le había quitado: se restaura.
                    $db->table('asignaciones_personal')->where('id', $existente->id)
                        ->update(['deleted_at' => null, 'rol' => $personal[0], 'updated_at' => now()]);

                    continue;
                }
                $db->table('asignaciones_personal')->insert([
                    'ulid' => (string) Str::ulid(),
                    'usuario_id' => $usuario->id,
                    'sucursal_id' => $sucursal,
                    'rol' => $personal[0],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Las asignaciones quedan: quitarlas dejaría a ese personal sin ver nada.
    }
};
