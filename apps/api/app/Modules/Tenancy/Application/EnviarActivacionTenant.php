<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Mail\CorreoActivacion;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Mail;

/**
 * Genera (o refresca) el token de activación de una cuenta tenant-local y envía el
 * correo transaccional con el enlace. Devuelve el token en claro (para poder
 * mostrarlo fuera de producción). Cierra el "alta autónoma": el propietario/invitado
 * siempre recibe un camino para activar su cuenta.
 */
class EnviarActivacionTenant
{
    public function __construct(private readonly ActivacionPropietario $activacion) {}

    public function enviar(Estudio $estudio, string $email): string
    {
        $token = $this->activacion->generar($estudio, $email);

        Mail::to($email)->queue(new CorreoActivacion(
            (string) $estudio->nombre,
            (string) $estudio->slug,
            $email,
            $token,
            $this->activacion->diasDeVigencia(),
        ));

        return $token;
    }
}
