<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\DomiciliacionesTenant;
use App\Modules\Tenancy\Application\FamiliasTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\MembresiasPorModalidadTenant;
use App\Modules\Tenancy\Application\RegistrarEventoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\Exceptions\DomiciliacionNoPermitida;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Support\MarcaProducto;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suscripciones recurrentes del estudio (Etapa 2): membresías con cobro recurrente
 * (llevan `proxima_cobro_en`), para dar visibilidad de las próximas renovaciones que
 * el scheduler cobrará y de cuáles se cobran solas (pago automático) o se avisan
 * para pagar a mano. El cobro lo ejecuta el comando `agendauno:cobrar-suscripciones`.
 *
 * El negocio no captura tarjetas: invita al alumno a activar su pago automático (él
 * lo autoriza en la pasarela) y puede quitarlo si el alumno lo pide. La de una persona
 * a cargo la puede pagar su responsable con su tarjeta (`paga`, ADR 0129).
 */
class SuscripcionesTenantController
{
    private const POR_PAGINA = 25;

    /** Un proceso de mora abierto. */
    private const ESTADOS_MORA = [EstadoDunning::EnMora->value, EstadoDunning::Suspendido->value];

    public function __construct(
        private readonly DomiciliacionesTenant $domiciliaciones,
        private readonly RegistrarEventoTenant $eventos,
        private readonly GestorDeConexionTenant $gestor,
        private readonly FamiliasTenant $familias,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * Las renovaciones por páginas (antes, solo las primeras 200), de la más cercana;
     * se buscan por la persona y se filtran por pago automático y por situación
     * (`proximas`: aún no vencen, hoy incluido; `vencidas`: ya pasó su fecha sin
     * cobrarse). Cada una dice cómo va con hoy del negocio (`situacion`, `dias`). El
     * resumen (sin filtros) da los indicadores.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'automatico' => ['nullable', 'in:si,no'],
            'situacion' => ['nullable', 'in:proximas,vencidas'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $hoy = $this->fechas->hoy();
        // Quien está acotado a sedes (R19) ve solo las de sus clientes.
        $base = app(AlcancePersonaTenant::class)->acotada(AcuerdoTenant::query()
            ->whereNotNull('proxima_cobro_en')
            ->where('estado', '!=', EstadoAcuerdo::Cancelado->value), $request);
        $resumen = [
            'total' => (clone $base)->count(),
            'automaticos' => (clone $base)->whereHas('domiciliacion')->count(),
            // Las que aún no vencen, las vencidas sin cobrar (fuera de mora) y las que
            // están en mora o suspendidas: no se cuentan dos veces.
            'proximas' => self::conSituacion(clone $base, 'proximas', $hoy)->count(),
            'vencidas' => self::conSituacion(clone $base, 'vencidas', $hoy)->count(),
            'en_mora' => self::enMora(clone $base)->count(),
        ];
        $buscar = trim((string) ($filtros['q'] ?? ''));
        $pagina = (clone $base)
            ->when($buscar !== '', fn ($q) => $q->whereHas('persona', fn ($p) => $p->buscar($buscar)))
            ->when(($filtros['automatico'] ?? null) === 'si', fn ($q) => $q->whereHas('domiciliacion'))
            ->when(($filtros['automatico'] ?? null) === 'no', fn ($q) => $q->whereDoesntHave('domiciliacion'))
            ->when(isset($filtros['situacion']), fn ($q) => self::conSituacion($q, (string) $filtros['situacion'], $hoy))
            ->with(['persona', 'producto', 'domiciliacion.persona'])
            ->orderBy('proxima_cobro_en')
            ->orderBy('id')
            ->paginate((int) ($filtros['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($filtros['page'] ?? 1));
        $acuerdos = $pagina->getCollection();
        // Pagos automáticos que se quitaron y la pasarela aún no confirma (ADR 0134).
        $cancelando = DomiciliacionTenant::query()
            ->whereIn('acuerdo_id', $acuerdos->modelKeys())
            ->where('estado', DomiciliacionTenant::CANCELANDO)
            ->pluck('acuerdo_id')
            ->all();
        // El proceso de mora abierto de cada una (en mora o suspendido), si lo tiene.
        /** @var array<int, string> $mora */
        $mora = ProcesoDunningTenant::query()
            ->whereIn('acuerdo_id', $acuerdos->modelKeys())
            ->whereIn('estado', self::ESTADOS_MORA)
            ->toBase()
            ->pluck('estado', 'acuerdo_id')
            ->all();
        $dia = CarbonImmutable::parse($hoy);
        $domiciliaciones = $this->domiciliaciones;

        return response()->json([
            'data' => $acuerdos->map(static fn (AcuerdoTenant $a): array => [
                'id' => $a->ulid,
                'persona' => $a->persona?->nombreCompleto(),
                'producto' => $a->producto?->nombre,
                'precio_minor' => $a->producto?->precio_minor,
                'moneda' => $a->producto?->moneda,
                'proxima_cobro_en' => $a->proxima_cobro_en?->toDateString(),
                'estado' => $a->estado->value,
                ...self::situacion($a, $mora[(int) $a->getKey()] ?? null, $dia),
                'cancelando_pago_automatico' => in_array($a->getKey(), $cancelando, false),
                'pago_automatico' => $a->domiciliacion instanceof DomiciliacionTenant
                    ? [
                        ...$a->domiciliacion->tarjeta(),
                        'error' => $a->domiciliacion->ultimo_error,
                        // Lo que autorizó (su precio contratado) y el nuevo, si cambió.
                        'monto_minor' => $domiciliaciones->precioContratado($a)['monto_minor'] ?? null,
                        'precio_nuevo_minor' => $domiciliaciones->nuevoPrecio($a),
                        // La paga su responsable con su tarjeta (ADR 0129).
                        'paga' => (int) $a->domiciliacion->persona_id !== (int) $a->persona_id
                            ? $a->domiciliacion->persona?->nombreCompleto()
                            : null,
                    ]
                    : null,
            ])->all(),
            'pago_automatico_disponible' => $this->domiciliaciones->proveedor() !== null,
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'resumen' => $resumen,
            ],
        ]);
    }

    /**
     * Correo al alumno con el enlace a su cuenta para activar el pago automático.
     */
    public function solicitarPagoAutomatico(Request $request): JsonResponse
    {
        $acuerdo = $this->acuerdo($request);
        if (app(MembresiasPorModalidadTenant::class)->enCitas()) {
            throw new DomiciliacionNoPermitida('En un negocio de citas no hay pago automático: los bonos se pagan al comprarlos.');
        }
        if ($this->domiciliaciones->proveedor() === null) {
            throw new DomiciliacionNoPermitida('Para ofrecer pago automático, conecta primero una pasarela en línea.');
        }
        if (! DomiciliacionesTenant::renovable($acuerdo)) {
            throw new DomiciliacionNoPermitida('Esta membresía no se renueva; no necesita pago automático.');
        }
        // Sin correo propio, le llega a su responsable con autorización vigente (ADR 0129).
        $persona = $acuerdo->persona;
        if ((string) $persona?->email === '' && ($persona === null || $this->familias->responsablesDe($persona)->isEmpty())) {
            throw new DomiciliacionNoPermitida('El alumno no tiene correo registrado.');
        }

        $this->eventos->registrar('pago_automatico.solicitado', 'acuerdo', (string) $acuerdo->ulid, [
            'acuerdo' => (string) $acuerdo->ulid,
            'persona_id' => $acuerdo->persona?->ulid,
            'producto' => (string) $acuerdo->producto?->nombre,
            'enlace' => MarcaProducto::actual()->urlWeb().'/entrar?estudio='.rawurlencode((string) $this->gestor->actual()?->slug),
        ]);

        return response()->json(['data' => ['enviado' => true]]);
    }

    /**
     * Quita el pago automático (p. ej. a petición del alumno): la renovación vuelve a
     * avisarse para pagarla a mano.
     */
    public function quitarPagoAutomatico(Request $request): JsonResponse
    {
        $this->domiciliaciones->desactivar($this->acuerdo($request));

        return response()->json(['data' => ['pago_automatico' => null]]);
    }

    private function acuerdo(Request $request): AcuerdoTenant
    {
        $acuerdo = AcuerdoTenant::query()
            ->where('ulid', (string) $request->route('acuerdo'))
            ->with(['persona', 'producto'])
            ->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirAcuerdo($request, $acuerdo);

        return $acuerdo;
    }

    /**
     * Cómo va la renovación con hoy del negocio: suspendida o en mora (el acuerdo
     * suspendido o su proceso de mora abierto) antes que en pausa y, si no, por su fecha
     * (próxima, hoy o vencida). `dias`: los que faltan o los que pasaron.
     *
     * @return array{situacion: string, dias: int}
     */
    private static function situacion(AcuerdoTenant $acuerdo, ?string $estadoMora, CarbonImmutable $hoy): array
    {
        // El listado solo trae las que tienen fecha (sin ella contaría como hoy).
        $fecha = $acuerdo->proxima_cobro_en !== null ? CarbonImmutable::parse($acuerdo->proxima_cobro_en->toDateString()) : $hoy;
        $situacion = match (true) {
            $acuerdo->estado === EstadoAcuerdo::Suspendido || $estadoMora === EstadoDunning::Suspendido->value => 'suspendida',
            $estadoMora === EstadoDunning::EnMora->value => 'en_mora',
            $acuerdo->estado === EstadoAcuerdo::Pausado => 'pausada',
            $fecha->greaterThan($hoy) => 'proxima',
            $fecha->equalTo($hoy) => 'hoy',
            default => 'vencida',
        };

        return ['situacion' => $situacion, 'dias' => (int) round(abs($hoy->diffInDays($fecha)))];
    }

    /**
     * Las que aún no vencen (`proximas`, hoy incluido) o ya pasaron su fecha sin
     * cobrarse (`vencidas`), sin las suspendidas, en mora o en pausa (tienen su propia
     * situación).
     *
     * @param  Builder<AcuerdoTenant>  $consulta
     * @return Builder<AcuerdoTenant>
     */
    private static function conSituacion(Builder $consulta, string $situacion, string $hoy): Builder
    {
        return $consulta
            ->whereNotIn('estado', [EstadoAcuerdo::Pausado->value, EstadoAcuerdo::Suspendido->value])
            ->whereNotIn('id', self::conMoraAbierta())
            ->whereDate('proxima_cobro_en', $situacion === 'vencidas' ? '<' : '>=', $hoy);
    }

    /**
     * Las suspendidas o con un proceso de mora abierto.
     *
     * @param  Builder<AcuerdoTenant>  $consulta
     * @return Builder<AcuerdoTenant>
     */
    private static function enMora(Builder $consulta): Builder
    {
        return $consulta->where(fn (Builder $q) => $q
            ->where('estado', EstadoAcuerdo::Suspendido->value)
            ->orWhereIn('id', self::conMoraAbierta()));
    }

    /**
     * Los acuerdos con un proceso de mora abierto (subconsulta).
     *
     * @return Builder<ProcesoDunningTenant>
     */
    private static function conMoraAbierta(): Builder
    {
        return ProcesoDunningTenant::query()->select('acuerdo_id')->whereIn('estado', self::ESTADOS_MORA);
    }
}
