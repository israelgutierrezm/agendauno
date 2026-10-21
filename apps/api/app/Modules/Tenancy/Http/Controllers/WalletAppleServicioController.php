<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\PaseAccesoTenant;
use App\Modules\Tenancy\Application\PasesWalletTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DispositivoPaseWallet;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PaseWallet;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * El servicio web de los pases de Apple Wallet (ADR 0195), como lo pide Apple
 * (`webServiceURL` del pase, `/api/v1/wallet/apple/v1/...`):
 *
 * - registrar y quitar un teléfono con su token de avisos;
 * - qué pases de un teléfono cambiaron desde la última vez;
 * - el pase nuevo (o 304 si no cambió).
 *
 * El teléfono se identifica con `Authorization: ApplePass {token}`, que va en el pase y
 * es propio de cada número de serie. No usa la sesión de nadie.
 */
class WalletAppleServicioController
{
    public function __construct(
        private readonly PasesWalletTenant $wallet,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function registrar(Request $request): Response
    {
        $pase = $this->paseAutorizado($request);
        $token = trim((string) $request->input('pushToken', ''));
        abort_if($token === '' || strlen($token) > 255, 400);
        $dispositivo = DispositivoPaseWallet::query()->firstOrNew([
            'pase_wallet_id' => $pase->getKey(),
            'dispositivo' => Str::limit((string) $request->route('dispositivo'), 128, ''),
        ]);
        $nuevo = ! $dispositivo->exists;
        $dispositivo->push_token = $token;
        $dispositivo->save();

        return response('', $nuevo ? 201 : 200);
    }

    public function quitar(Request $request): Response
    {
        $pase = $this->paseAutorizado($request);
        DispositivoPaseWallet::query()->where('pase_wallet_id', $pase->getKey())
            ->where('dispositivo', (string) $request->route('dispositivo'))->delete();

        return response('', 200);
    }

    /** Los números de serie de un teléfono que cambiaron desde `passesUpdatedSince`. */
    public function cambiados(Request $request): JsonResponse|Response
    {
        $this->exigirTipo($request);
        $desde = (string) $request->query('passesUpdatedSince', '');
        $pases = PaseWallet::query()
            ->whereHas('dispositivos', fn ($q) => $q->where('dispositivo', (string) $request->route('dispositivo')))
            ->when($desde !== '' && ctype_digit($desde), fn ($q) => $q->where('cambio_en', '>', Carbon::createFromTimestamp((int) $desde)))
            ->get();
        if ($pases->isEmpty()) {
            return response('', 204);
        }

        return response()->json([
            'serialNumbers' => $pases->pluck('serie')->values()->all(),
            'lastUpdated' => (string) ($pases->max(fn (PaseWallet $p): int => (int) $p->cambio_en?->getTimestamp())),
        ]);
    }

    /** El pase como está ahora; 304 si no cambió desde `If-Modified-Since`. */
    public function pase(Request $request): Response
    {
        $pase = $this->paseAutorizado($request);
        $cambio = $pase->cambio_en ?? $pase->updated_at;
        $desde = $request->headers->get('If-Modified-Since');
        if ($desde !== null && $cambio !== null && strtotime($desde) !== false && $cambio->getTimestamp() <= strtotime($desde)) {
            return response('', 304);
        }
        $estudio = $pase->estudio;
        abort_unless($estudio instanceof Estudio && $this->wallet->appleDisponible(), 404);

        $contenido = $this->gestor->ejecutarEn($estudio, function () use ($estudio, $pase): ?string {
            $persona = PersonaTenant::query()->where('ulid', $pase->persona_ulid)->first();
            if (! $persona instanceof PersonaTenant || ! app(PaseAccesoTenant::class)->walletPermitido()) {
                return null;
            }

            return $this->wallet->apple($estudio, $persona);
        });
        abort_if($contenido === null, 404);

        return response($contenido, 200, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Last-Modified' => ($cambio ?? now())->toRfc7231String(),
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Lo que el teléfono reporta de los errores de sus pases. */
    public function bitacora(Request $request): Response
    {
        $mensajes = array_slice((array) $request->input('logs', []), 0, 20);
        foreach ($mensajes as $mensaje) {
            Log::info('Apple Wallet: '.Str::limit((string) (is_scalar($mensaje) ? $mensaje : ''), 300));
        }

        return response('', 200);
    }

    private function paseAutorizado(Request $request): PaseWallet
    {
        $this->exigirTipo($request);
        $serie = (string) $request->route('serie');
        $autorizacion = (string) $request->headers->get('Authorization', '');
        $token = str_starts_with($autorizacion, 'ApplePass ') ? substr($autorizacion, strlen('ApplePass ')) : '';
        abort_unless($token !== '' && hash_equals(PasesWalletTenant::tokenApple($serie), $token), 401);
        $pase = PaseWallet::query()->where('serie', $serie)->first();
        abort_unless($pase instanceof PaseWallet, 404);

        return $pase;
    }

    private function exigirTipo(Request $request): void
    {
        abort_unless((string) $request->route('tipo') === (string) config('agendauno.wallet.apple.pass_type_id'), 404);
    }
}
