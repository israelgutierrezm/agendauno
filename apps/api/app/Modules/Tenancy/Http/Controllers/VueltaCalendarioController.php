<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Calendarios\CalendariosExternosTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * La vuelta de Google o Microsoft tras autorizar el calendario (ADR 0199): una sola URL
 * para todos los negocios (la que se registra en el cliente OAuth). No canjea nada:
 * lleva el código a Mi perfil en la web del negocio y ahí se confirma con la sesión.
 */
class VueltaCalendarioController
{
    public function __invoke(Request $request, CalendariosExternosTenant $calendarios): RedirectResponse
    {
        $proveedor = (string) $request->route('proveedor');
        $estado = $calendarios->leerEstado((string) $request->query('state', ''));
        $estudio = $estado !== null && $estado['proveedor'] === $proveedor
            ? Estudio::query()->where('slug', $estado['estudio'])->first()
            : null;
        $base = MarcaProducto::urlWeb($estudio).'/mi-perfil';

        $codigo = (string) $request->query('code', '');
        if ($estudio === null || $codigo === '') {
            return redirect()->away($base.'?'.http_build_query(['calendario' => $request->query('error') !== null ? 'cancelado' : 'error']));
        }

        return redirect()->away($base.'?'.http_build_query([
            'calendario' => $proveedor,
            'codigo' => $codigo,
            'estado' => (string) $request->query('state'),
        ]));
    }
}
