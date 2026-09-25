<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\Push\ClienteFcm;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DispositivoPushTenant;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Throwable;

/**
 * Entrega de un mensaje por push: a cada teléfono donde su destinatario (el usuario
 * del equipo al que va, o si no el de la persona) tiene la app con sesión en este
 * negocio. Los tokens que FCM ya no reconoce se olvidan. Si ningún
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
        return $this->fcm->configurado() && $this->dispositivos(self::usuarioDe($persona))->isNotEmpty();
    }

    /**
     * ¿Se le puede mandar push a este usuario del equipo?
     */
    public function puedeRecibirUsuario(Usuario $usuario): bool
    {
        return $this->fcm->configurado() && $this->dispositivos((int) $usuario->getKey())->isNotEmpty();
    }

    public function entregar(MensajeTenant $mensaje): void
    {
        $usuario = $mensaje->usuario_id !== null ? (int) $mensaje->usuario_id : self::usuarioDe($mensaje->persona);
        $dispositivos = $this->dispositivos($usuario);
        if ($dispositivos->isEmpty()) {
            throw new RuntimeException('El destinatario ya no tiene la app con sesión en ningún teléfono.');
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

        throw new RuntimeException('Ningún teléfono del destinatario aceptó la notificación.');
    }

    /**
     * El usuario de la persona (su cuenta en la app), si sigue activa.
     */
    private static function usuarioDe(?PersonaTenant $persona): ?int
    {
        if (! $persona instanceof PersonaTenant || $persona->trashed() || $persona->usuario_id === null) {
            return null;
        }

        return (int) $persona->usuario_id;
    }

    /**
     * @return Collection<int, DispositivoPushTenant>
     */
    private function dispositivos(?int $usuarioId): Collection
    {
        if ($usuarioId === null) {
            return new Collection;
        }

        return DispositivoPushTenant::query()->where('usuario_id', $usuarioId)->orderBy('id')->get();
    }
}
