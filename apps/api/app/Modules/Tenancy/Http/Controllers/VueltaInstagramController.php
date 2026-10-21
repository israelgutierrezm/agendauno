<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Bandeja\BandejaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * La vuelta de Instagram tras conectar la cuenta de la bandeja (ADR 0200): una sola URL
 * para todos los negocios. No canjea nada: lleva el código a la bandeja en la web del
 * negocio y ahí se confirma con la sesión.
 */
class VueltaInstagramController
{
    public function __invoke(Request $request, BandejaTenant $bandeja): RedirectResponse
    {
        $estado = $bandeja->leerEstado((string) $request->query('state', ''));
        $estudio = $estado !== null ? Estudio::query()->where('slug', $estado['estudio'])->first() : null;
        $base = MarcaProducto::urlWeb($estudio).'/bandeja';
        // Instagram agrega «#_» al final del código.
        $codigo = (string) preg_replace('/#_$/', '', (string) $request->query('code', ''));

        if ($estudio === null || $codigo === '') {
            return redirect()->away($base.'?'.http_build_query(['instagram' => $request->query('error') !== null ? 'cancelado' : 'error']));
        }

        return redirect()->away($base.'?'.http_build_query(['instagram' => 'conectar', 'codigo' => $codigo, 'estado' => (string) $request->query('state')]));
    }
}
