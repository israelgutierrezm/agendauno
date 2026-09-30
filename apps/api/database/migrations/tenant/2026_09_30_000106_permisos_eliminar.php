<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| «Eliminar» aparte de «gestionar» donde se borra o se da de baja (ADR 0077). Los
| roles propios que ya existían reciben el permiso de eliminar de cada área que
| gestionaban, para que nadie pierda lo que hoy puede hacer.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    /** @var array<string, string> */
    private const NUEVOS = [
        'miembros.gestionar' => 'miembros.eliminar',
        'usuarios.gestionar' => 'usuarios.eliminar',
        'agenda.gestionar' => 'agenda.eliminar',
        'productos.gestionar' => 'productos.eliminar',
        'promociones.gestionar' => 'promociones.eliminar',
        'comunicaciones.gestionar' => 'comunicaciones.eliminar',
        'automatizaciones.gestionar' => 'automatizaciones.eliminar',
    ];

    public function up(): void
    {
        $this->cambiar(function (array $permisos): array {
            foreach (self::NUEVOS as $gestionar => $eliminar) {
                if (in_array($gestionar, $permisos, true) && ! in_array($eliminar, $permisos, true)) {
                    $permisos[] = $eliminar;
                }
            }

            return $permisos;
        });
    }

    public function down(): void
    {
        $this->cambiar(fn (array $permisos): array => array_values(array_diff($permisos, array_values(self::NUEVOS))));
    }

    /**
     * @param  callable(list<string>): list<string>  $cambio
     */
    private function cambiar(callable $cambio): void
    {
        $roles = DB::connection('tenant')->table('roles')->orderBy('id')->get(['id', 'permisos']);
        foreach ($roles as $rol) {
            $permisos = json_decode((string) $rol->permisos, true);
            if (! is_array($permisos)) {
                continue;
            }
            /** @var list<string> $permisos */
            $nuevos = $cambio($permisos);
            if ($nuevos !== $permisos) {
                DB::connection('tenant')->table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($nuevos)]);
            }
        }
    }
};
