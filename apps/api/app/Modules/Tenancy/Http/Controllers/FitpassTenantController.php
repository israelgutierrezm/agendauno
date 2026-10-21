<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CupoDeSesionTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PublicacionPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ProveedorPartner;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Fitpass sin API (ADR 0197 y 0202): sus horarios se capturan a mano en Fitpass Admin.
 * Aquí el negocio marca qué clases ofrece en Fitpass (con el nombre de su «disciplina»
 * allá) y obtiene sus horarios de los próximos días, con los lugares para Fitpass, para
 * capturarlos (y las canceladas, para quitarlas allá).
 */
class FitpassTenantController
{
    /** Días hacia adelante que se pueden pedir (Fitpass deja reservar una semana). */
    private const MAX_DIAS = 14;

    public function __construct(
        private readonly CupoDeSesionTenant $cupo,
        private readonly FechasNegocioTenant $fechas,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function clases(): JsonResponse
    {
        $publicaciones = PublicacionPlataformaTenant::query()->where('proveedor', ProveedorPartner::Fitpass->value)->get()->keyBy('oferta_id');

        return response()->json(['data' => OfertaTenant::query()
            ->vigentes()
            ->where('modalidad', ModalidadOfertaTenant::Grupal->value)
            ->with('actividad')
            ->get()
            ->sortBy(fn (OfertaTenant $o): string => mb_strtolower(($o->actividad->nombre ?? '').' '.$o->nombre))
            ->map(fn (OfertaTenant $o): array => [
                'oferta_id' => $o->ulid,
                'nombre' => $o->nombre,
                'actividad' => $o->actividad?->nombre,
                'ofrecida' => (bool) $publicaciones->get($o->getKey())?->activa,
                'disciplina' => $publicaciones->get($o->getKey())?->producto_externo,
            ])->values()->all()]);
    }

    public function guardarClases(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'clases' => ['required', 'array', 'max:300'],
            'clases.*.oferta_id' => ['required', 'string', 'max:40'],
            'clases.*.ofrecida' => ['required', 'boolean'],
            'clases.*.disciplina' => ['nullable', 'string', 'max:40'],
        ]);
        $ofertas = OfertaTenant::query()->whereIn('ulid', array_column($validado['clases'], 'oferta_id'))->get()->keyBy('ulid');
        foreach ($validado['clases'] as $i => $clase) {
            $oferta = $ofertas->get($clase['oferta_id']);
            if (! $oferta instanceof OfertaTenant) {
                throw ValidationException::withMessages(["clases.{$i}.oferta_id" => 'La clase no existe.']);
            }
            PublicacionPlataformaTenant::query()->updateOrCreate(
                ['proveedor' => ProveedorPartner::Fitpass->value, 'oferta_id' => $oferta->getKey()],
                ['activa' => (bool) $clase['ofrecida'], 'producto_externo' => trim((string) ($clase['disciplina'] ?? '')) ?: null],
            );
        }
        $usuario = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar($usuario instanceof Usuario ? $usuario : null, 'integracion.fitpass_clases', 'integracion', ProveedorPartner::Fitpass->value, null, [
            'ofrecidas' => PublicacionPlataformaTenant::query()->where('proveedor', ProveedorPartner::Fitpass->value)->where('activa', true)->count(),
        ]);

        return $this->clases();
    }

    /**
     * Los horarios de las clases que se ofrecen en Fitpass de los próximos días, como se
     * capturan en Fitpass Admin: fecha y hora de su sede, disciplina, quién la da y los
     * lugares para Fitpass. Las canceladas también, para quitarlas allá.
     */
    public function horarios(Request $request): JsonResponse
    {
        $validado = $request->validate(['dias' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DIAS]]);
        $dias = (int) ($validado['dias'] ?? 7);
        $publicaciones = PublicacionPlataformaTenant::query()->where('proveedor', ProveedorPartner::Fitpass->value)->where('activa', true)->get()->keyBy('oferta_id');
        if ($publicaciones->isEmpty()) {
            return response()->json(['data' => []]);
        }
        $sesiones = SesionTenant::query()
            ->whereIn('oferta_id', $publicaciones->keys()->all())
            ->where('tipo', TipoSesionTenant::Clase->value)
            ->whereIn('estado', [EstadoSesionTenant::Programada->value, EstadoSesionTenant::Cancelada->value])
            ->where('inicia_en', '>', now())
            ->where('inicia_en', '<=', now()->addDays($dias))
            ->withCount(CupoDeSesionTenant::conteos())
            ->with(['oferta.actividad', 'instructor', 'sucursal'])
            ->orderBy('inicia_en')
            ->get();
        $this->cupo->cargar($sesiones, ProveedorPartner::Fitpass->value);

        return response()->json(['data' => $sesiones->map(function (SesionTenant $s) use ($publicaciones): array {
            $zona = $s->zona_horaria !== '' ? $s->zona_horaria : $this->fechas->zona();
            $inicio = CarbonImmutable::instance($s->inicia_en)->setTimezone($zona);

            return [
                'id' => $s->ulid,
                'fecha' => $inicio->toDateString(),
                'hora' => $inicio->format('H:i'),
                'duracion_minutos' => (int) $s->inicia_en->diffInMinutes($s->termina_en),
                'clase' => trim(($s->oferta?->actividad->nombre ?? '').' '.($s->oferta->nombre ?? '')),
                'disciplina' => $publicaciones->get($s->oferta_id)?->producto_externo,
                'instructor' => $s->instructor?->name,
                'sucursal' => $s->sucursal?->nombre,
                'estado' => $s->estado->value,
                // Lo que Fitpass aún puede tomar (null: la clase no tiene cupo).
                'lugares' => $s->estado === EstadoSesionTenant::Programada ? $this->cupo->disponibles($s, ProveedorPartner::Fitpass->value) : 0,
            ];
        })->values()->all()]);
    }
}
