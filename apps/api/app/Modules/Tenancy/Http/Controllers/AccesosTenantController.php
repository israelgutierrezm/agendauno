<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Acceso\MetodoAcceso;
use App\Modules\Tenancy\Acceso\ResultadoAcceso;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\PaseAccesoTenant;
use App\Modules\Tenancy\Application\RegistrarAccesoTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\AccesoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Control de acceso del estudio (R12): la puerta/recepcion registra un intento de
 * entrada (credencial QR/PIN/NFC) y el motor decide (reserva vigente u OPEN_ACCESS),
 * dejando la bitacora. Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class AccesosTenantController
{
    private const POR_PAGINA = 50;

    public function __construct(
        private readonly RegistrarAccesoTenant $accesos,
        private readonly PaseAccesoTenant $pases,
        private readonly FechasNegocioTenant $fechas,
        private readonly ResolverAccesoTenant $alcance,
    ) {}

    public function registrar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            // El pase QR del alumno (firmado y con vencimiento) o, a mano, su ulid.
            'codigo' => ['required_without:persona_id', 'nullable', 'string', 'max:200'],
            'persona_id' => ['required_without:codigo', 'nullable', 'string'],
            'metodo' => ['required_without:codigo', 'nullable', Rule::enum(MetodoAcceso::class)],
            'sucursal_id' => ['nullable', 'string'],
        ]);

        $codigo = $validado['codigo'] ?? null;
        if (is_string($codigo) && $codigo !== '') {
            $persona = $this->pases->resolver($codigo);
            $metodo = MetodoAcceso::Qr;
        } else {
            $persona = PersonaTenant::query()->where('ulid', (string) $validado['persona_id'])->firstOrFail();
            $metodo = MetodoAcceso::from((string) $validado['metodo']);
        }
        $sucursalId = null;
        if (($validado['sucursal_id'] ?? '') !== '') {
            $sucursalId = (int) SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail()->getKey();
        }

        $acceso = $this->accesos->registrar($persona, $metodo, $sucursalId, Carbon::now());

        return response()->json(['data' => $this->presentar($acceso)], 201);
    }

    /**
     * La bitácora de entradas por páginas, de la más reciente a la más antigua: se busca
     * por la persona y se acota por resultado y por fechas (días del negocio). Quien
     * está acotado a sus sucursales solo ve las entradas de ellas y las registradas sin
     * sucursal (R19). `meta.conteos`
     * cuenta lo encontrado por resultado, sin ese filtro.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'resultado' => ['nullable', Rule::enum(ResultadoAcceso::class)],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (isset($filtros['desde'], $filtros['hasta']) && $filtros['hasta'] < $filtros['desde']) {
            throw ValidationException::withMessages(['hasta' => ['La fecha final no puede ser antes que la inicial.']]);
        }

        $actor = $request->attributes->get('usuario_tenant');
        $sedes = $actor instanceof Usuario ? $this->alcance->sucursalesPermitidas($actor) : null;
        $q = trim((string) ($filtros['q'] ?? ''));
        $encontrados = fn (): Builder => AccesoTenant::query()
            // Las registradas sin sucursal (a mano desde la ficha) las ve todo el equipo.
            ->when($sedes !== null, fn ($c) => $c->where(fn ($w) => $w->whereIn('sucursal_id', $sedes)->orWhereNull('sucursal_id')))
            ->when($q !== '', fn ($c) => $c->whereHas('persona', fn ($p) => $p->buscar($q)))
            ->when(isset($filtros['desde']), fn ($c) => $c->where('registrado_en', '>=', $this->fechas->inicioDelDia((string) $filtros['desde'])))
            ->when(isset($filtros['hasta']), fn ($c) => $c->where('registrado_en', '<=', $this->fechas->finDelDia((string) $filtros['hasta'])));

        $pagina = $encontrados()
            ->when(isset($filtros['resultado']), fn ($c) => $c->where('resultado', $filtros['resultado']))
            ->with('persona')
            ->orderByDesc('registrado_en')
            ->orderByDesc('id')
            ->paginate((int) ($filtros['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($filtros['page'] ?? 1));
        $porResultado = $encontrados()->selectRaw('resultado, COUNT(*) as total')->groupBy('resultado')->toBase()->pluck('total', 'resultado');

        return response()->json([
            'data' => collect($pagina->items())->map(fn (AccesoTenant $a): array => $this->presentar($a))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'conteos' => collect(ResultadoAcceso::cases())
                    ->mapWithKeys(fn (ResultadoAcceso $r): array => [$r->value => (int) ($porResultado[$r->value] ?? 0)])
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(AccesoTenant $acceso): array
    {
        return [
            'id' => $acceso->ulid,
            'persona' => $acceso->persona?->nombre,
            'persona_id' => $acceso->persona?->ulid,
            'persona_nombre' => $acceso->persona?->nombreCompleto(),
            'metodo' => $acceso->metodo->value,
            'resultado' => $acceso->resultado->value,
            'permitido' => $acceso->resultado->value === 'permitido',
            'codigo' => $acceso->codigo,
            'registrado_en' => $acceso->registrado_en->toIso8601String(),
        ];
    }
}
