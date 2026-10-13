<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\PublicarEnPlataformasTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\IntegracionSoloMexico;
use App\Modules\Tenancy\Integraciones\ClienteTotalPassReservas;
use App\Modules\Tenancy\Integraciones\ClienteWellhubReservas;
use App\Modules\Tenancy\Integraciones\PublicadorPlataforma;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\HorarioPlataformaTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PublicacionPlataformaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ProveedorPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function Illuminate\Support\defer;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reservas desde la app de Wellhub o TotalPass (ADR 0149): qué clases publica el negocio,
 * con qué producto (Wellhub) o plan (TotalPass) y de qué sucursal.
 *
 * - `GET /integraciones/{proveedor}/reservas`: lo configurado, los productos de la
 *   plataforma (consultados en el momento), cómo va lo publicado y las reservas por venir.
 * - `PUT /integraciones/{proveedor}/reservas`: lo guarda. Al encenderlo, registra con la
 *   plataforma la dirección de sus avisos y publica después de responder.
 *
 * Solo con la integración conectada y activa, en negocios de clases y en México.
 */
class ReservasPlataformaTenantController
{
    public function __construct(
        private readonly RegionNegocioTenant $region,
        private readonly RegistrarAuditoria $auditoria,
        private readonly PublicarEnPlataformasTenant $publicar,
        private readonly ParametrosTenant $parametros,
        private readonly ClienteWellhubReservas $wellhub,
        private readonly ClienteTotalPassReservas $totalpass,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $proveedor = $this->proveedor($request);
        $integracion = IntegracionTenant::query()->where('proveedor', $proveedor->value)->first();

        return response()->json(['data' => $this->presentar($proveedor, $integracion, $this->productos($proveedor, $integracion))]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $proveedor = $this->proveedor($request);
        $publicador = $this->publicar->publicador($proveedor);
        $integracion = IntegracionTenant::query()->where('proveedor', $proveedor->value)->first();
        if (! $publicador instanceof PublicadorPlataforma || ! $this->disponible($proveedor)) {
            throw ValidationException::withMessages(['activas' => 'Aún no se publican clases en '.$proveedor->nombre().'.']);
        }
        if (! $integracion instanceof IntegracionTenant || ! $integracion->activa) {
            throw ValidationException::withMessages(['activas' => 'Primero conecta y activa la integración con sus llaves.']);
        }

        $validado = $request->validate([
            'activas' => ['required', 'boolean'],
            'sucursal_id' => ['nullable', 'string', 'max:40', 'required_if_accepted:activas'],
            'clases' => ['sometimes', 'array', 'max:300'],
            'clases.*.oferta_id' => ['required', 'string', 'max:40'],
            'clases.*.publicada' => ['required', 'boolean'],
            'clases.*.producto' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ]);

        $sucursal = null;
        if (($validado['sucursal_id'] ?? null) !== null) {
            $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->first();
            if (! $sucursal instanceof SucursalTenant) {
                throw ValidationException::withMessages(['sucursal_id' => 'La sucursal no existe.']);
            }
        }

        /** @var list<array{oferta_id: string, publicada: bool, producto?: string|null}> $clases */
        $clases = $validado['clases'] ?? [];
        $ofertas = OfertaTenant::query()->whereIn('ulid', array_column($clases, 'oferta_id'))->get()->keyBy('ulid');
        $actuales = PublicacionPlataformaTenant::query()->where('proveedor', $proveedor->value)->with('oferta.actividad')->get()->keyBy('oferta_id');
        $cambios = [];
        foreach ($clases as $i => $clase) {
            $oferta = $ofertas->get($clase['oferta_id']);
            if (! $oferta instanceof OfertaTenant) {
                throw ValidationException::withMessages(["clases.{$i}.oferta_id" => 'La clase no existe.']);
            }
            $producto = trim((string) ($clase['producto'] ?? ''));
            if ($clase['publicada'] && $producto === '') {
                throw ValidationException::withMessages(["clases.{$i}.producto" => 'Elige con qué '.$proveedor->producto().' de '.$proveedor->nombre().' se ofrece «'.$oferta->nombre.'».']);
            }
            $publicacion = $actuales->get($oferta->getKey());
            if (! $publicacion instanceof PublicacionPlataformaTenant && ! $clase['publicada']) {
                // Una clase que nunca se publicó y sigue sin publicarse no deja rastro.
                continue;
            }
            $publicacion ??= new PublicacionPlataformaTenant([
                'proveedor' => $proveedor->value,
                'oferta_id' => $oferta->getKey(),
            ]);
            $publicacion->activa = (bool) $clase['publicada'];
            if ($producto !== '') {
                $publicacion->producto_externo = $producto;
            }
            $cambios[] = $publicacion;
        }

        // Una clase ya publicada que cambia de producto se cambia antes en la plataforma.
        $credenciales = $integracion->llaves();
        foreach ($cambios as $publicacion) {
            if ($publicacion->exists && $publicacion->isDirty('producto_externo')) {
                $error = $publicador->cambiarProducto($publicacion, $credenciales);
                if ($error !== null) {
                    throw ValidationException::withMessages(['clases' => $error]);
                }
            }
        }

        $antes = ['activas' => $integracion->reservas_activas, 'sucursal_id' => $integracion->sucursal_id];
        DB::connection('tenant')->transaction(function () use ($integracion, $validado, $sucursal, $cambios): void {
            $integracion->reservas_activas = (bool) $validado['activas'];
            $integracion->sucursal_id = $sucursal?->getKey() !== null ? (int) $sucursal->getKey() : $integracion->sucursal_id;
            $integracion->save();
            foreach ($cambios as $publicacion) {
                $publicacion->save();
            }
        });

        // Al encenderlo, la plataforma necesita la dirección de sus avisos de reserva.
        $errorAvisos = null;
        if ($integracion->reservas_activas && $integracion->reservas_registradas_en === null) {
            $errorAvisos = $publicador->registrarAvisos(
                $integracion,
                IntegracionesTenantController::urlDeAvisos($proveedor, (string) $integracion->token_webhook),
            );
        }

        $usuario = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar(
            $usuario instanceof Usuario ? $usuario : null,
            'integracion.reservas',
            'integracion',
            $proveedor->value,
            $antes,
            [
                'activas' => $integracion->reservas_activas,
                'sucursal_id' => $integracion->sucursal_id,
                'clases_publicadas' => PublicacionPlataformaTenant::query()->where('proveedor', $proveedor->value)->where('activa', true)->count(),
            ],
        );

        // Publica (o retira) después de responder; el proceso de cada cinco minutos sigue.
        $estudio = $this->gestor->actual();
        if ($estudio instanceof Estudio) {
            $id = (int) $integracion->getKey();
            defer(function () use ($estudio, $id): void {
                $this->gestor->ejecutarAislado($estudio, function () use ($id): void {
                    $integracion = IntegracionTenant::query()->find($id);
                    if ($integracion instanceof IntegracionTenant) {
                        $this->publicar->sincronizarIntegracion($integracion);
                    }
                }, null);
            });
        }

        return response()->json(['data' => [
            ...$this->presentar($proveedor, $integracion->refresh(), null),
            'error_avisos' => $errorAvisos,
        ]]);
    }

    /**
     * @param  list<array{id: string, nombre: string, virtual: bool}>|null  $productos
     * @return array<string, mixed>
     */
    private function presentar(ProveedorPartner $proveedor, ?IntegracionTenant $integracion, ?array $productos): array
    {
        $publicaciones = PublicacionPlataformaTenant::query()->where('proveedor', $proveedor->value)->get()->keyBy('oferta_id');
        $ofertas = OfertaTenant::query()
            ->where('modalidad', ModalidadOfertaTenant::Grupal->value)
            ->with('actividad')
            ->get()
            ->sortBy(fn (OfertaTenant $o): string => mb_strtolower(($o->actividad->nombre ?? '').' '.$o->nombre))
            ->values();
        $sucursales = SucursalTenant::query()->orderBy('nombre')->get(['id', 'ulid', 'nombre']);
        $ahora = now();
        $futuros = HorarioPlataformaTenant::query()
            ->where('proveedor', $proveedor->value)
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', $ahora));
        $token = $integracion instanceof IntegracionTenant ? (string) $integracion->token_webhook : '';

        return [
            'proveedor' => $proveedor->value,
            'disponible' => $this->disponible($proveedor),
            'conectada' => $integracion instanceof IntegracionTenant && $integracion->activa,
            // Con la integración apagada no se publica nada, aunque siga encendido.
            'activas' => $integracion instanceof IntegracionTenant && $integracion->activa && $integracion->reservas_activas,
            'sucursal_id' => $sucursales->firstWhere('id', $integracion?->sucursal_id)?->getAttribute('ulid'),
            'sucursales' => $sucursales->map(fn (SucursalTenant $s): array => [
                'id' => (string) $s->getAttribute('ulid'),
                'nombre' => (string) $s->nombre,
            ])->values()->all(),
            'dias_publicados' => $this->parametros->entero('plataformas.dias_publicados'),
            'lugares_sin_tope' => $this->parametros->entero('plataformas.lugares_sin_tope'),
            'avisos' => [
                'registrados' => $integracion?->reservas_registradas_en !== null,
                'registrado_en' => $integracion?->reservas_registradas_en?->toIso8601String(),
                'aviso_url' => $token !== '' ? IntegracionesTenantController::urlDeAvisos($proveedor, $token) : null,
            ],
            'productos' => $productos,
            'clases' => $ofertas->map(function (OfertaTenant $oferta) use ($publicaciones): array {
                $publicacion = $publicaciones->get($oferta->getKey());

                return [
                    'oferta_id' => (string) $oferta->getAttribute('ulid'),
                    'nombre' => trim(($oferta->actividad->nombre ?? '').' · '.$oferta->nombre, ' ·'),
                    'publicada' => $publicacion instanceof PublicacionPlataformaTenant && $publicacion->activa,
                    'producto' => $publicacion?->producto_externo,
                    'error' => $publicacion?->error,
                ];
            })->all(),
            'horarios' => [
                'publicados' => (clone $futuros)->where('estado', HorarioPlataformaTenant::PUBLICADO)->count(),
                'con_error' => (clone $futuros)->whereNotNull('error')->count(),
                'ultimo_error' => (clone $futuros)->whereNotNull('error')->latest('updated_at')->value('error'),
                'sincronizado_en' => HorarioPlataformaTenant::query()->where('proveedor', $proveedor->value)
                    ->whereNotNull('sincronizado_en')->latest('sincronizado_en')->first()?->sincronizado_en?->toIso8601String(),
            ],
            'reservas_por_venir' => LugarPlataformaTenant::query()
                ->where('proveedor', $proveedor->value)
                ->where('origen', LugarPlataformaTenant::ORIGEN_RESERVA)
                ->where('estado', LugarPlataformaTenant::RESERVADO)
                ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', $ahora))
                ->count(),
        ];
    }

    /**
     * ¿Ya se publican clases en esa plataforma? TotalPass necesita además la llave de
     * AgendaUno como integrador.
     */
    private function disponible(ProveedorPartner $proveedor): bool
    {
        return $this->publicar->publicador($proveedor) instanceof PublicadorPlataforma
            && ($proveedor !== ProveedorPartner::TotalPass || $this->totalpass->disponible());
    }

    /**
     * Los productos (Wellhub) o planes (TotalPass) con que se ofrece cada clase; null si
     * no se pudieron consultar (sin llaves o sin respuesta).
     *
     * @return list<array{id: string, nombre: string, virtual: bool}>|null
     */
    private function productos(ProveedorPartner $proveedor, ?IntegracionTenant $integracion): ?array
    {
        if (! $integracion instanceof IntegracionTenant || ! $integracion->activa || ! $this->disponible($proveedor)) {
            return null;
        }

        return match ($proveedor) {
            ProveedorPartner::Wellhub => $this->wellhub->productos($integracion->llaves()),
            ProveedorPartner::TotalPass => $this->totalpass->planes($integracion->llaves()['place_api_key'] ?? ''),
        };
    }

    private function proveedor(Request $request): ProveedorPartner
    {
        if (! $this->region->enMexico()) {
            throw new IntegracionSoloMexico('Wellhub y TotalPass solo están disponibles para negocios en México.');
        }
        $proveedor = ProveedorPartner::tryFrom((string) $request->route('proveedor'));
        abort_if($proveedor === null, 404);

        return $proveedor;
    }
}
