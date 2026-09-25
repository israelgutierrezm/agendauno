<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Comunicaciones\SegmentoComunicacion;
use App\Modules\Tenancy\Models\DifusionTenant;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Difusión de una comunicación a un SEGMENTO (comunicaciones segmentadas). Resuelve la
 * audiencia en vivo ({@see ResolverSegmentoTenant}) y encola un {@see MensajeTenant} por
 * destinatario, renderizando los marcadores {{persona_nombre}}/{{persona_email}} — NO
 * envía: eso lo hace el relay {@see EnviarMensajesTenant}. En canal `email` omite a
 * quien no tiene correo y en `push` a quien no tiene la app con sesión. Todo en una transacción del tenant (la conexión ya debe estar
 * activa). Devuelve el encabezado de la difusión con el total ENCOLADO.
 */
class DifundirComunicacionTenant
{
    public function __construct(
        private ResolverSegmentoTenant $resolver,
        private EntregarPushTenant $push,
    ) {}

    public function ejecutar(
        SegmentoComunicacion $segmento,
        CanalComunicacion $canal,
        string $asunto,
        string $cuerpo,
    ): DifusionTenant {
        $personas = $this->resolver->resolver($segmento);

        return DB::connection('tenant')->transaction(function () use ($segmento, $canal, $asunto, $cuerpo, $personas): DifusionTenant {
            $difusion = DifusionTenant::query()->create([
                'segmento' => $segmento->value,
                'canal' => $canal->value,
                'asunto' => $asunto,
                'cuerpo' => $cuerpo,
                'total' => 0,
            ]);

            $total = 0;
            foreach ($personas as $persona) {
                $destinatario = null;
                if ($canal === CanalComunicacion::Email) {
                    $email = $persona->email;
                    if (! is_string($email) || $email === '') {
                        continue; // sin correo no se puede encolar un email
                    }
                    $destinatario = $email;
                }
                if ($canal === CanalComunicacion::Push && ! $this->push->puedeRecibir($persona)) {
                    continue; // sin la app con sesión no hay a dónde mandarla
                }

                $contexto = $this->contexto($persona);

                MensajeTenant::query()->create([
                    'persona_id' => $persona->getKey(),
                    'difusion_id' => $difusion->getKey(),
                    'canal' => $canal->value,
                    'destinatario' => $destinatario,
                    'asunto' => $this->render($asunto, $contexto),
                    'cuerpo' => $this->render($cuerpo, $contexto),
                    'estado' => EstadoMensaje::Encolado->value,
                    'evento_ulid' => 'difusion:'.$difusion->ulid,
                ]);

                $total++;
            }

            $difusion->total = $total;
            $difusion->enviada_en = Carbon::now();
            $difusion->save();

            return $difusion;
        });
    }

    /**
     * Marcadores disponibles al renderizar asunto/cuerpo por destinatario.
     *
     * @return array<string, string>
     */
    private function contexto(PersonaTenant $persona): array
    {
        return [
            'persona_nombre' => $persona->nombreCompleto(),
            'persona_email' => (string) ($persona->email ?? ''),
        ];
    }

    /**
     * Sustituye los marcadores {{clave}} por su valor del contexto.
     *
     * @param  array<string, string>  $contexto
     */
    private function render(string $texto, array $contexto): string
    {
        foreach ($contexto as $clave => $valor) {
            $texto = str_replace('{{'.$clave.'}}', $valor, $texto);
        }

        return $texto;
    }
}
