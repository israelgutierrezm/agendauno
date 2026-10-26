<?php

declare(strict_types=1);

namespace App\Modules\Platform\Legales;

use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Mail\CorreoLegalesNuevos;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Se publicó una versión nueva del aviso de privacidad o de los términos (ADR 0216):
 * cada dueño de un negocio operativo (no de demostración) recibe un correo con la fecha
 * y el enlace para leerla. La acepta la próxima vez que entra a su panel.
 */
class AvisarLegalesNuevos implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tipo,
        public readonly int $version,
    ) {}

    public function handle(): void
    {
        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value, EstadoEstudio::Suspended->value])
            ->where('es_demo', false)
            ->whereNotNull('contacto_email')
            ->chunkById(200, function (Collection $estudios): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    $correo = (string) $estudio->contacto_email;
                    if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
                        continue;
                    }
                    $ruta = $this->tipo === DocumentoLegal::AVISO ? '/aviso-de-privacidad' : '/terminos';
                    Mail::to($correo)->queue(new CorreoLegalesNuevos(
                        (string) $estudio->nombre,
                        $this->tipo,
                        MarcaProducto::urlWeb($estudio).$ruta,
                        $estudio->producto(),
                    ));
                }
            });
    }
}
