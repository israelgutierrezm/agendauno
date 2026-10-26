<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Mail\CorreoExportacionLista;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ExportacionNegocio;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Arma en cola la descarga de todos los datos del negocio (ADR 0213) y avisa por correo
 * a quien la pidió. Si falla, la descarga queda fallida con el motivo y se puede pedir
 * otra.
 */
class ArmarExportacionNegocio implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $exportacionId) {}

    public function handle(ExportacionDeDatosNegocio $exportar): void
    {
        $exportacion = ExportacionNegocio::query()->find($this->exportacionId);
        $estudio = $exportacion instanceof ExportacionNegocio ? Estudio::query()->find($exportacion->estudio_id) : null;
        if (! $exportacion instanceof ExportacionNegocio || ! $estudio instanceof Estudio || $exportacion->estado !== ExportacionNegocio::EN_PROCESO) {
            return;
        }
        try {
            $exportar->armar($estudio, $exportacion);
        } catch (Throwable $e) {
            report($e);
            $exportacion->update(['estado' => ExportacionNegocio::FALLIDA, 'error' => Str::limit($e->getMessage(), 490)]);

            return;
        }
        $correo = (string) $exportacion->correo;
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) !== false) {
            $volver = $estudio->enPlazoDeBaja() ? '/negocio-dado-de-baja' : '/renta';
            Mail::to($correo)->queue(new CorreoExportacionLista(
                (string) $estudio->nombre,
                MarcaProducto::urlWeb($estudio).'/entrar?estudio='.rawurlencode((string) $estudio->slug).'&volver='.rawurlencode($volver),
                ExportacionDeDatosNegocio::DIAS,
                $estudio->producto(),
            ));
        }
    }
}
