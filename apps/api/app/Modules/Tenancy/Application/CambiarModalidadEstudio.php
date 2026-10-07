<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\ModalidadEnUso;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\PerfilNegocio;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Cambia la modalidad de un negocio (ADR 0104): solo el superadmin, y solo mientras
 * su base no tenga sesiones ni reservas (como la moneda, que solo cambia antes de
 * cobrar): lo que ya existe es de la modalidad con que se creó. Con la modalidad
 * cambia su giro (el elegido o el predeterminado de la nueva) y su métrica de cobro.
 * Queda en el log de la plataforma y en la bitácora del negocio.
 */
class CambiarModalidadEstudio
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * ¿Aún se puede cambiar? Sin base (o sin esquema) no hay nada creado; si la base
     * no se puede leer, no se arriesga el cambio.
     */
    public function cambiable(Estudio $estudio): bool
    {
        if (! $this->gestor->baseDeDatosExiste($estudio)) {
            return true;
        }

        try {
            return $this->gestor->ejecutarEn(
                $estudio,
                static fn (): bool => ! SesionTenant::query()->exists() && ! ReservaTenant::query()->exists(),
            );
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * @throws ModalidadEnUso si ya tiene sesiones o reservas
     * @throws ValidationException si el giro es de la otra modalidad
     */
    public function cambiar(Estudio $estudio, ModalidadServicio $modalidad, ?PerfilNegocio $perfil = null): Estudio
    {
        if ($perfil !== null && ModalidadServicio::paraPerfil($perfil) !== $modalidad) {
            throw ValidationException::withMessages(['perfil_negocio' => ["Ese giro no trabaja con {$modalidad->value}."]]);
        }

        $antes = ['modalidad' => $estudio->modalidad()->value, 'perfil' => $estudio->perfil_negocio->value];
        $perfil ??= ModalidadServicio::paraPerfil($estudio->perfil_negocio) === $modalidad
            ? $estudio->perfil_negocio
            : $modalidad->perfilPredeterminado();
        $despues = ['modalidad' => $modalidad->value, 'perfil' => $perfil->value];
        if ($antes === $despues) {
            return $estudio;
        }
        // Un cambio de giro dentro de la misma modalidad solo cambia la terminología.
        if ($antes['modalidad'] !== $despues['modalidad'] && ! $this->cambiable($estudio)) {
            throw new ModalidadEnUso('Este negocio ya tiene sesiones o reservas: su modalidad ya no se puede cambiar.');
        }

        $estudio->forceFill(['modalidad' => $modalidad, 'perfil_negocio' => $perfil])->save();

        Log::info('plataforma.estudio.modalidad', ['estudio' => $estudio->slug, 'antes' => $antes, 'despues' => $despues]);
        if ($this->gestor->baseDeDatosExiste($estudio)) {
            try {
                $this->gestor->ejecutarEn($estudio, fn () => $this->auditoria->registrar(
                    null, 'estudio.modalidad', 'estudio', null, $antes, $despues, 'Cambio hecho por AgendaUno.',
                ));
            } catch (QueryException) {
                // Base aún sin esquema (aprovisionándose): basta el log de la plataforma.
            }
        }

        return $estudio->refresh();
    }
}
