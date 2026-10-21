<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\Models\SolicitudWellhub;
use App\Modules\Tenancy\ProveedorPartner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * AgendaUno como sistema integrado de Wellhub (ADR 0202): un gimnasio elige a AgendaUno
 * en Wellhub y Wellhub avisa a la plataforma (`SYSTEM_INTEGRATION_REQUESTED`) con su Gym
 * ID y los campos que AgendaUno le pidió (por ejemplo `cms_id`). Si un campo es el código
 * de un negocio (su slug, como lo ve en Integraciones), el Gym ID se le pone solo en su
 * integración de Wellhub (apagada: el dueño la activa); si no, queda pendiente y el
 * superadmin lo asigna.
 */
class SistemaWellhub
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly AlertasPlataforma $alertas,
    ) {}

    /**
     * Registra el aviso (una vez) e intenta asignarlo. Null si no es un aviso de alta.
     *
     * @param  array<string, mixed>  $aviso
     */
    public function recibir(array $aviso): ?SolicitudWellhub
    {
        // Su documentación dice SYSTEM_INTEGRATION_REQUESTED y su ejemplo
        // system-integration-requested; los campos van en `event_data` o en la raíz.
        $tipo = strtoupper(str_replace('-', '_', (string) Arr::get($aviso, 'event_type', Arr::get($aviso, 'type', ''))));
        if ($tipo !== 'SYSTEM_INTEGRATION_REQUESTED') {
            return null;
        }
        $datos = is_array($aviso['event_data'] ?? null) ? $aviso['event_data'] : $aviso;
        $gym = trim((string) ($datos['gym_id'] ?? ''));
        $evento = trim((string) ($datos['event_id'] ?? '')) ?: hash('sha256', (string) json_encode($datos));
        if ($gym === '' || preg_match('/^[0-9A-Za-z_\-]{1,40}$/', $gym) !== 1) {
            return null;
        }
        $existente = SolicitudWellhub::query()->where('evento_id', Str::limit($evento, 64, ''))->first();
        if ($existente instanceof SolicitudWellhub) {
            return $existente;
        }
        try {
            $solicitud = SolicitudWellhub::query()->create([
                'evento_id' => Str::limit($evento, 64, ''),
                'gym_id' => $gym,
                'gimnasio' => Str::limit(trim((string) ($datos['gym_name'] ?? '')), 160, '') ?: null,
                'socio_id' => Str::limit(trim((string) ($datos['partner_id'] ?? '')), 64, '') ?: null,
                'campos' => array_values(array_filter((array) ($datos['custom_fields'] ?? []), 'is_array')),
                'estado' => SolicitudWellhub::PENDIENTE,
                'recibida_en' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return SolicitudWellhub::query()->where('evento_id', Str::limit($evento, 64, ''))->firstOrFail();
        }

        $estudio = $this->negocioDeLosCampos($solicitud);
        if ($estudio instanceof Estudio && $this->asignar($solicitud, $estudio) === null) {
            return $solicitud->refresh();
        }
        $this->alertas->registrar('wellhub_solicitud', 'wellhub:'.$gym,
            'Un gimnasio eligió a AgendaUno en Wellhub (Gym ID '.$gym.') y no se supo de qué negocio es: asígnalo en el superadmin.');

        return $solicitud;
    }

    /**
     * Le pone el Gym ID a la integración de Wellhub del negocio. Null si quedó; si no,
     * por qué.
     */
    public function asignar(SolicitudWellhub $solicitud, Estudio $estudio): ?string
    {
        if (! $this->gestor->baseDeDatosExiste($estudio)) {
            return 'El negocio aún no tiene su base de datos.';
        }
        $error = $this->gestor->ejecutarEn($estudio, function () use ($solicitud): ?string {
            $conexiones = IntegracionTenant::query()->where('proveedor', ProveedorPartner::Wellhub->value)->orderBy('id')->get();
            // Ya la tiene, o una conexión (una sede) aún sin Gym ID la toma.
            $integracion = $conexiones->first(fn (IntegracionTenant $i): bool => trim($i->llaves()['gym_id'] ?? '') === $solicitud->gym_id)
                ?? $conexiones->first(fn (IntegracionTenant $i): bool => trim($i->llaves()['gym_id'] ?? '') === '');
            if (! $integracion instanceof IntegracionTenant) {
                $conGym = $conexiones->first(fn (IntegracionTenant $i): bool => $i->sucursal_id === null);
                if ($conGym instanceof IntegracionTenant) {
                    return 'El negocio ya tiene otro Gym ID de Wellhub ('.trim($conGym->llaves()['gym_id'] ?? '').'): revísalo con el dueño.';
                }
                $integracion = new IntegracionTenant(['proveedor' => ProveedorPartner::Wellhub->value]);
            }
            $llaves = $integracion->llaves();
            $integracion->credenciales = [...$llaves, 'gym_id' => $solicitud->gym_id];
            $integracion->configurada_en ??= now();
            if (! $integracion->exists) {
                $integracion->activa = false;
                $integracion->token_webhook = Str::random(40);
            }
            $integracion->save();

            return null;
        });
        if ($error !== null) {
            $solicitud->forceFill(['nota' => Str::limit($error, 290, '')])->save();

            return $error;
        }
        $solicitud->forceFill([
            'estudio_id' => $estudio->getKey(),
            'estado' => SolicitudWellhub::ASIGNADA,
            'nota' => null,
            'atendida_en' => now(),
        ])->save();

        return null;
    }

    /** El negocio cuyo código (su slug o su identificador público) vino en un campo. */
    private function negocioDeLosCampos(SolicitudWellhub $solicitud): ?Estudio
    {
        foreach ($solicitud->campos ?? [] as $campo) {
            $valor = mb_strtolower(trim((string) ($campo['value'] ?? '')));
            if ($valor === '') {
                continue;
            }
            $estudio = Estudio::query()->where('slug', $valor)->orWhere('ulid', strtoupper($valor))->first();
            if ($estudio instanceof Estudio) {
                return $estudio;
            }
        }

        return null;
    }
}
