<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\Push\ClienteFcm;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DispositivoPushTenant;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Throwable;

/**
 * Entrega de un mensaje por push: a cada teléfono donde la persona tiene la app con
 * sesión en este negocio. Los tokens que FCM ya no reconoce se olvidan. Si ningún
 * teléfono lo recibió por un error pasajero, se lanza para que el relay reintente.
 */
class EntregarPushTenant
{
    public function __construct(
        private readonly ClienteFcm $fcm,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /**
     * ¿Se le puede mandar push? (FCM configurado y la app con sesión en algún teléfono).
     */
    public function puedeRecibir(?PersonaTenant $persona): bool
    {
        return $this->fcm->configurado() && $this->dispositivos($persona)->isNotEmpty();
    }

    public function entregar(MensajeTenant $mensaje): void
    {
        $dispositivos = $this->dispositivos($mensaje->persona);
        if ($dispositivos->isEmpty()) {
            throw new RuntimeException('La persona ya no tiene la app con sesión en ningún teléfono.');
        }

        $datos = [
            'estudio' => (string) $this->gestor->actual()?->slug,
            'mensaje' => (string) $mensaje->ulid,
            'tipo' => $mensaje->difusion_id !== null ? 'difusion' : (string) $mensaje->plantilla?->clave,
        ];

        $entregados = 0;
        $pasajero = null;
        foreach ($dispositivos as $dispositivo) {
            try {
                if ($this->fcm->enviar($dispositivo->token, $mensaje->asunto, $mensaje->cuerpo, $datos)) {
                    $entregados++;
                } else {
                    $dispositivo->delete();
                }
            } catch (Throwable $e) {
                $pasajero = $e;
            }
        }

        if ($entregados > 0) {
            return;
        }
        if ($pasajero !== null) {
            throw $pasajero;
        }

        throw new RuntimeException('Ningún teléfono de la persona aceptó la notificación.');
    }

    /**
     * @return Collection<int, DispositivoPushTenant>
     */
    private function dispositivos(?PersonaTenant $persona): Collection
    {
        if (! $persona instanceof PersonaTenant || $persona->trashed() || $persona->usuario_id === null) {
            return new Collection;
        }

        return DispositivoPushTenant::query()->where('usuario_id', $persona->usuario_id)->orderBy('id')->get();
    }
}
