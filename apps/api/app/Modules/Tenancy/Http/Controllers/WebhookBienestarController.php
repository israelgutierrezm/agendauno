<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AvisosPlataformaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Integraciones\RegistroDeIntegracionesTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\ProveedorPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function Illuminate\Support\defer;

/**
 * Los avisos de Wellhub y TotalPass de un negocio (ADR 0148), de check-in y de las
 * reservas desde su app (ADR 0149): `POST /webhooks/bienestar/{estudio}/{proveedor}/{token}`.
 *
 * - El token de la dirección es el del negocio con esa plataforma (se genera al
 *   conectarla): sin él, el aviso no se acepta.
 * - Wellhub firma cada aviso (`X-Gympass-Signature`, HMAC-SHA1 del cuerpo en
 *   mayúsculas) con la llave secreta que entrega; sin llave, en producción no se acepta.
 * - TotalPass no documenta firma: lo que valida la visita es confirmar su token con
 *   TotalPass, así que un aviso falso no aprueba nada.
 *
 * El aviso se guarda y se contesta al momento (Wellhub espera un segundo); se procesa
 * después de responder y, si algo queda pendiente, el comando de cada minuto lo retoma.
 */
class WebhookBienestarController
{
    public function __construct(
        private readonly RegistroDeIntegracionesTenant $registro,
        private readonly AvisosPlataformaTenant $avisos,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $proveedor = ProveedorPartner::tryFrom((string) $request->route('proveedor'));
        abort_if($proveedor === null, 404);

        $config = IntegracionTenant::query()->where('proveedor', $proveedor->value)->first();
        $token = (string) $request->route('token');
        abort_unless(
            $config instanceof IntegracionTenant
            && (string) $config->token_webhook !== ''
            && hash_equals((string) $config->token_webhook, $token)
            && $this->registro->activa($proveedor->value),
            404,
        );

        $cuerpo = $request->getContent();
        if ($proveedor === ProveedorPartner::Wellhub) {
            $this->verificarFirmaWellhub($cuerpo, $request->header('X-Gympass-Signature'), $config);
        }

        $datos = json_decode($cuerpo, true);
        abort_unless(is_array($datos), 400, 'El aviso no es JSON.');

        $evento = $this->avisos->recibir($proveedor, $datos);
        $estudio = $this->gestor->actual();
        if ($evento instanceof EventoPlataformaTenant && $estudio instanceof Estudio) {
            $id = (int) $evento->getKey();
            defer(function () use ($estudio, $id): void {
                $this->gestor->ejecutarAislado($estudio, function () use ($id): void {
                    $evento = EventoPlataformaTenant::query()->find($id);
                    if ($evento instanceof EventoPlataformaTenant) {
                        $this->avisos->procesar($evento);
                    }
                }, null);
            });
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    private function verificarFirmaWellhub(string $cuerpo, ?string $firma, IntegracionTenant $config): void
    {
        $secreto = $config->llaves()['webhook_secret'] ?? (string) config('agendauno.integraciones.wellhub_webhook_secret');
        if ($secreto === '') {
            abort_if(! app()->environment(['local', 'testing']), 400, 'Aviso sin llave secreta configurada.');

            return;
        }
        // Wellhub la manda en mayúsculas; algunos ejemplos la muestran con «0X» al frente.
        $recibida = strtoupper((string) preg_replace('/^0X/i', '', trim((string) $firma)));
        $esperada = strtoupper(hash_hmac('sha1', $cuerpo, $secreto));
        abort_unless($recibida !== '' && hash_equals($esperada, $recibida), 401, 'Firma inválida.');
    }
}
