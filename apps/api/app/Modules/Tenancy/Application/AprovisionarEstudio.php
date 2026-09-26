<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\TarifaSaas;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Aprovisiona el data plane de un estudio: crea su BD, corre sus migraciones y
 * crea el usuario propietario tenant-local (sin contraseña; se define al activar).
 * Idempotente y reanudable: si el estudio ya está operativo no re-hace nada, y
 * cada paso usa operaciones idempotentes (crear-si-no-existe). Al terminar inicia
 * el periodo de prueba y deja el estudio en `trialing`.
 *
 * Para BD costosas (MySQL) conviene ejecutarlo desde una cola; en dev/test (SQLite)
 * es barato y corre en línea.
 */
class AprovisionarEstudio
{
    // Si aún no hay tarifa publicada para la modalidad.
    private const DIAS_TRIAL = 30;

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    public function ejecutar(Estudio $estudio): Estudio
    {
        if ($estudio->estado->operativo()) {
            return $estudio; // ya aprovisionado
        }

        // 1. BD del tenant + migraciones (crear-si-falta); anota la versión del esquema.
        $this->gestor->aprovisionarBaseDeDatos($estudio);
        $estudio->update(['paso_aprovisionamiento' => 'migrado']);

        // 2. Propietario tenant-local (idempotente por email dentro de la BD del tenant).
        $this->gestor->ejecutarEn($estudio, function () use ($estudio): void {
            Usuario::query()->firstOrCreate(
                ['email' => $estudio->contacto_email],
                ['name' => $estudio->nombreContacto(), 'password' => null, 'activo' => false, 'rol' => 'propietario', 'roles' => ['propietario']],
            );
            // Roles/permisos canónicos tenant-local: fase posterior (migración de Authorization).
        });
        $estudio->update(['paso_aprovisionamiento' => 'owner_creado']);

        // 3. Trial + estado operativo.
        $estudio->update([
            'estado' => EstadoEstudio::Trialing->value,
            'estado_facturacion' => EstadoFacturacion::Trial->value,
            'trial_inicia_en' => now()->toDateString(),
            'trial_termina_en' => now()->addDays(TarifaSaas::vigente($estudio->modalidad())?->diasPrueba() ?? self::DIAS_TRIAL)->toDateString(),
            'aprovisionado_en' => now(),
            'paso_aprovisionamiento' => null,
        ]);

        return $estudio->refresh();
    }
}
