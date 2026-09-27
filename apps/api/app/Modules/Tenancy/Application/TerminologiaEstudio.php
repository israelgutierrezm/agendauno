<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\TerminologiaNegocio;

/**
 * Terminología de un negocio para editarla (ADR 0049): la del perfil de su giro, la
 * que eligió encima y la que queda vigente. La usan el administrador del negocio y el
 * superadmin.
 */
final class TerminologiaEstudio
{
    /**
     * @return array{opciones: array<string, list<string>>, del_perfil: array<string, string>, propia: array<string, string>, vigente: array<string, string>}
     */
    public function paraEditar(Estudio $estudio): array
    {
        $propia = [];
        foreach ((array) ($estudio->terminologia ?? []) as $clave => $valor) {
            if (is_string($valor) && isset(TerminologiaNegocio::OPCIONES[$clave][$valor])) {
                $propia[$clave] = $valor;
            }
        }

        return [
            'opciones' => TerminologiaNegocio::opciones(),
            'del_perfil' => $estudio->perfil_negocio->configuracion()['terminologia'],
            'propia' => $propia,
            'vigente' => $estudio->perfilConfig()['terminologia'],
        ];
    }

    /**
     * `valores`: {termino: opción | null}; null o vacío vuelve al del perfil. Lo que no
     * viene se conserva.
     *
     * @param  array<string, mixed>  $valores
     * @return array{antes: array<string, string>, despues: array<string, string>}
     */
    public function guardar(Estudio $estudio, array $valores): array
    {
        $antes = $this->paraEditar($estudio)['propia'];
        $elegidos = TerminologiaNegocio::validar($valores);

        $propia = $antes;
        foreach (array_keys($valores) as $clave) {
            unset($propia[$clave]);
        }
        $propia = [...$propia, ...$elegidos];
        $estudio->update(['terminologia' => $propia === [] ? null : $propia]);

        return ['antes' => $antes, 'despues' => $propia];
    }
}
