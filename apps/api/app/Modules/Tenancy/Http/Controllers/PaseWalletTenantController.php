<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\Application\PaseAccesoTenant;
use App\Modules\Tenancy\Application\PasesWalletTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * El pase de entrada en la cartera del teléfono (ADR 0195): qué carteras se ofrecen, el
 * `.pkpass` de Apple Wallet, el enlace de Google Wallet y renovarlo (el anterior deja
 * de servir).
 */
class PaseWalletTenantController
{
    public function __construct(
        private readonly PasesWalletTenant $wallet,
        private readonly PaseAccesoTenant $pases,
        private readonly CuentaEnUsoTenant $cuenta,
    ) {}

    public function disponibles(Request $request): JsonResponse
    {
        $this->personaDe($request);
        $permitido = $this->pases->walletPermitido();

        return response()->json(['data' => [
            'apple' => $permitido && $this->wallet->appleDisponible(),
            'google' => $permitido && $this->wallet->googleDisponible(),
        ]]);
    }

    public function apple(Request $request): Response
    {
        $persona = $this->personaDe($request);
        abort_unless($this->pases->walletPermitido() && $this->wallet->appleDisponible(), 404);

        return response($this->wallet->apple($this->estudio($request), $persona), 200, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Content-Disposition' => 'attachment; filename="pase.pkpass"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function google(Request $request): JsonResponse
    {
        $persona = $this->personaDe($request);
        abort_unless($this->pases->walletPermitido() && $this->wallet->googleDisponible(), 404);

        return response()->json(['data' => ['url' => $this->wallet->google($this->estudio($request), $persona)]]);
    }

    /** Lo renueva: los pases que ya estaban en la cartera dejan de servir. */
    public function renovar(Request $request): JsonResponse
    {
        $persona = $this->personaDe($request);
        $this->pases->codigoDeWallet($persona, renovar: true);

        return response()->json(['data' => ['renovado' => true]]);
    }

    private function personaDe(Request $request): PersonaTenant
    {
        $persona = $this->cuenta->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        return $persona;
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
