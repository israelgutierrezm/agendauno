<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Comunicaciones\SegmentoComunicacion;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DifusionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaWhatsAppNegocio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Difusión de una comunicación a un SEGMENTO (comunicaciones segmentadas). Resuelve la
 * audiencia en vivo ({@see ResolverSegmentoTenant}) y encola un {@see MensajeTenant} por
 * destinatario, renderizando los marcadores {{persona_nombre}}/{{persona_email}} — NO
 * envía: eso lo hace el relay {@see EnviarMensajesTenant}. En canal `email` omite a
 * quien no tiene correo y en `push` a quien no tiene la app con sesión. Todo en una transacción del tenant (la conexión ya debe estar
 * activa). Devuelve el encabezado de la difusión con el total ENCOLADO.
 *
 * Por WhatsApp (ADR 0190) va con una plantilla aprobada del negocio, solo a quien
 * aceptó sus avisos por WhatsApp y tiene un celular válido; cada mensaje gasta del
 * saldo según la categoría de la plantilla.
 */
class DifundirComunicacionTenant
{
    public function __construct(
        private ResolverSegmentoTenant $resolver,
        private EntregarPushTenant $push,
        private RegionNegocioTenant $region,
        private GestorDeConexionTenant $gestor,
        private PlantillasWhatsAppNegocio $plantillas,
        private MensajesWhatsAppTenant $saldoWhatsApp,
    ) {}

    public function ejecutar(
        SegmentoComunicacion $segmento,
        CanalComunicacion $canal,
        string $asunto,
        string $cuerpo,
        ?PlantillaWhatsAppNegocio $plantilla = null,
    ): DifusionTenant {
        ['enviables' => $personas] = $this->destinatarios($this->resolver->resolver($segmento), $canal);
        $estudio = $this->gestor->actual();
        if ($canal === CanalComunicacion::WhatsApp && (! $plantilla instanceof PlantillaWhatsAppNegocio || ! $estudio instanceof Estudio)) {
            throw new \InvalidArgumentException('Por WhatsApp se manda con una plantilla aprobada.');
        }

        return DB::connection('tenant')->transaction(function () use ($segmento, $canal, $asunto, $cuerpo, $personas, $plantilla, $estudio): DifusionTenant {
            $difusion = DifusionTenant::query()->create([
                'segmento' => $segmento->value,
                'canal' => $canal->value,
                'asunto' => $asunto,
                'cuerpo' => $cuerpo,
                'total' => 0,
                'estado' => DifusionTenant::ENVIANDO,
                'huella' => self::huella($segmento, $canal, $asunto, $cuerpo),
            ]);

            $total = 0;
            $unidades = $plantilla instanceof PlantillaWhatsAppNegocio ? $this->saldoWhatsApp->unidadesPor($plantilla->categoria) : 1;
            foreach ($personas as [$persona, $destinatario]) {
                $contexto = $this->contexto($persona);

                if ($plantilla instanceof PlantillaWhatsAppNegocio && $estudio instanceof Estudio) {
                    $valores = $this->plantillas->valores($plantilla, $estudio, $persona);
                    MensajeTenant::query()->create([
                        'persona_id' => $persona->getKey(),
                        'difusion_id' => $difusion->getKey(),
                        'canal' => $canal->value,
                        'destinatario' => $destinatario,
                        'asunto' => $plantilla->titulo,
                        'cuerpo' => $this->render($plantilla->cuerpo, ['persona_nombre' => trim((string) $persona->nombre), 'negocio' => (string) $estudio->nombre]),
                        'parametros' => ['plantilla' => $plantilla->nombre_meta, 'valores' => $valores, 'unidades' => $unidades],
                        'estado' => EstadoMensaje::Encolado->value,
                        'evento_ulid' => 'difusion:'.$difusion->ulid,
                    ]);
                    $total++;

                    continue;
                }

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
     * A cuántos llegaría la campaña por ese canal y quiénes quedan fuera y por qué, con
     * la misma regla que el envío (ADR 0166): lo que se ve antes de enviar es lo que se
     * manda.
     *
     * Por WhatsApp, con la plantilla, también cuántos mensajes del saldo gasta y su costo
     * (si la plataforma los cobra).
     *
     * @return array{elegibles: int, sin_consentimiento: int, sin_email: int, sin_app: int, sin_whatsapp: int, mensajes?: int, costo_minor?: int|null, costo_moneda?: string}
     */
    public function alcance(SegmentoComunicacion $segmento, CanalComunicacion $canal, ?PlantillaWhatsAppNegocio $plantilla = null): array
    {
        $calculo = $this->destinatarios($this->resolver->resolver($segmento), $canal);
        $alcance = [
            'elegibles' => count($calculo['enviables']),
            'sin_consentimiento' => $this->resolver->sinConsentimiento($segmento),
            'sin_email' => $calculo['sin_email'],
            'sin_app' => $calculo['sin_app'],
            'sin_whatsapp' => $calculo['sin_whatsapp'],
        ];
        if ($canal === CanalComunicacion::WhatsApp && $plantilla instanceof PlantillaWhatsAppNegocio) {
            $mensajes = $alcance['elegibles'] * $this->saldoWhatsApp->unidadesPor($plantilla->categoria);
            $alcance['mensajes'] = $mensajes;
            $alcance['costo_minor'] = $this->saldoWhatsApp->cobra() ? $mensajes * $this->saldoWhatsApp->precioMinor() : null;
            // Pesos en México (más IVA); dólares sin IVA fuera (ADR 0186).
            $alcance['costo_moneda'] = $this->saldoWhatsApp->moneda();
        }

        return $alcance;
    }

    /**
     * A quién se le puede mandar por ese canal (con su destinatario) y cuántos no: sin
     * correo no hay email; sin la app con sesión, no hay push.
     *
     * @param  iterable<PersonaTenant>  $personas
     * @return array{enviables: list<array{0: PersonaTenant, 1: string|null}>, sin_email: int, sin_app: int, sin_whatsapp: int}
     */
    private function destinatarios(iterable $personas, CanalComunicacion $canal): array
    {
        $enviables = [];
        $sinEmail = 0;
        $sinApp = 0;
        $sinWhatsApp = 0;
        foreach ($personas as $persona) {
            $destinatario = null;
            if ($canal === CanalComunicacion::WhatsApp) {
                // Solo a quien aceptó sus avisos por WhatsApp, con un celular válido
                // (sin «+», del país del negocio; ADR 0103).
                $telefono = $persona->whatsapp_aceptado_en !== null
                    ? TelefonoWhatsApp::normalizar($persona->celular, $this->region->lada())
                    : null;
                if ($telefono === null) {
                    $sinWhatsApp++;

                    continue;
                }
                $destinatario = $telefono;
            }
            if ($canal === CanalComunicacion::Email) {
                $email = $persona->email;
                if (! is_string($email) || $email === '') {
                    $sinEmail++;

                    continue;
                }
                $destinatario = $email;
            }
            if ($canal === CanalComunicacion::Push && ! $this->push->puedeRecibir($persona)) {
                $sinApp++;

                continue;
            }
            $enviables[] = [$persona, $destinatario];
        }

        return ['enviables' => $enviables, 'sin_email' => $sinEmail, 'sin_app' => $sinApp, 'sin_whatsapp' => $sinWhatsApp];
    }

    /**
     * Identifica una campaña por su contenido y a quién va: la misma no se manda dos
     * veces el mismo día (B08).
     */
    public static function huella(SegmentoComunicacion $segmento, CanalComunicacion $canal, string $asunto, string $cuerpo): string
    {
        return hash('sha256', implode('|', [$segmento->value, $canal->value, trim($asunto), trim($cuerpo)]));
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
