<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CambiarModalidadEstudio;
use App\Modules\Tenancy\Application\DomiciliacionRenta;
use App\Modules\Tenancy\Application\PlanCitasSaas;
use App\Modules\Tenancy\Application\SuspensionPorRenta;
use App\Modules\Tenancy\Application\TerminologiaEstudio;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\AvisoDueno;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaPlataforma;
use App\Modules\Tenancy\Models\MedicionUso;
use App\Modules\Tenancy\PerfilNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ficha de un estudio para el operador de la plataforma (PlatformAdmin): datos del
 * negocio y su contacto, uso medido, cargos de renta y facturas; y las acciones de
 * soporte sobre su cuenta (suspender, reactivar, extender la prueba gratis, cambiar
 * su terminología, activar sus avisos por WhatsApp, cambiar entre clases y citas
 * antes de que opere).
 */
class PlataformaEstudiosController
{
    private const MESES_USO = 6;

    private const CARGOS = 12;

    public function show(string $estudio, ClienteWhatsApp $whatsapp, CambiarModalidadEstudio $cambioModalidad, PlanCitasSaas $planes, GestorDeConexionTenant $gestor): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();

        $uso = MedicionUso::query()
            ->where('estudio_id', $modelo->getKey())
            ->orderByDesc('periodo')
            ->limit(self::MESES_USO)
            ->get()
            ->map(static fn (MedicionUso $m): array => [
                'periodo' => $m->periodo,
                'metrica' => $m->metrica,
                'cantidad' => $m->cantidad,
                'congelada' => $m->congelada,
            ])->values()->all();

        $facturas = FacturaPlataforma::query()
            ->where('estudio_id', $modelo->getKey())
            ->get()
            ->keyBy('cargo_renta_id');

        $cargos = CargoRenta::query()
            ->where('estudio_id', $modelo->getKey())
            ->orderByDesc('periodo')
            ->orderByDesc('id')
            ->limit(self::CARGOS)
            ->get()
            ->map(static fn (CargoRenta $c): array => [
                'id' => $c->ulid,
                'periodo' => $c->periodo,
                // `renta`, `plan`, `ajuste` o `timbres` (ADR 0107).
                'concepto' => $c->concepto ?? 'renta',
                'monto_minor' => $c->monto_minor,
                'moneda' => $c->moneda,
                'estado' => $c->estado->value,
                'vence_en' => $c->vence_en?->toDateString(),
                'pagado_en' => $c->pagado_en?->toIso8601String(),
                'factura' => $facturas->get($c->getKey())?->estado->value,
            ])->values()->all();

        return response()->json(['data' => [
            ...self::resumen($modelo),
            'contacto' => [
                'nombre' => $modelo->nombreContacto(),
                'email' => $modelo->contacto_email,
                'whatsapp' => $modelo->whatsappCompleto(),
                // Confirmó su número con un código y aceptó avisos (ADR 0070).
                'whatsapp_verificado' => $modelo->contacto_whatsapp_verificado_en !== null,
            ],
            'onboarding_completo' => (bool) $modelo->onboarding_completo,
            // Clases o citas solo cambia antes de operar (ADR 0104).
            'modalidad_cambiable' => $cambioModalidad->cambiable($modelo),
            'whatsapp_clientes' => self::whatsappClientes($modelo, $whatsapp),
            // Su plan de citas (ADR 0107), para verlo y cambiarlo desde soporte.
            'plan_citas' => $planes->aplica($modelo) && $gestor->baseDeDatosExiste($modelo) ? $planes->resumen($modelo) : null,
            'uso' => $uso,
            'cargos' => $cargos,
            // Los últimos avisos de la plataforma al dueño (ADR 0071), para soporte.
            'avisos' => AvisoDueno::query()
                ->where('estudio_id', $modelo->getKey())
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(static fn (AvisoDueno $a): array => [
                    'id' => $a->getKey(),
                    'tipo' => $a->tipo,
                    'canal' => $a->canal->value,
                    'estado' => $a->estado->value,
                    // WhatsApp (ADR 0074): si le llegó y si lo leyó.
                    'entregado' => $a->entregado_en !== null,
                    'leido' => $a->leido_en !== null,
                    'fecha' => ($a->enviado_en ?? $a->created_at)?->toIso8601String(),
                ])->all(),
        ]]);
    }

    /**
     * Cambia el plan de un negocio de citas (ADR 0107) con las mismas reglas que su
     * dueño: subir aplica hoy y cobra la diferencia de los días que faltan (con su
     * tarjeta, si la domicilió); bajar o cambiar a anual, desde el siguiente periodo.
     * `cobrar_diferencia` en falso sube sin cobrar la diferencia (cortesía).
     */
    public function plan(Request $request, string $estudio, PlanCitasSaas $planes, DomiciliacionRenta $domiciliacion, GestorDeConexionTenant $gestor): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();
        $validado = $request->validate([
            'nivel' => ['required', Rule::in(PlanCitasSaas::NIVELES)],
            'profesionales' => ['required', 'integer', 'min:1', 'max:1000'],
            'periodicidad' => ['required', Rule::in(PlanCitasSaas::PERIODICIDADES)],
            'cobrar_diferencia' => ['sometimes', 'boolean'],
        ]);
        if (! $gestor->baseDeDatosExiste($modelo)) {
            throw ValidationException::withMessages(['estado' => ['El negocio aún no tiene su base de datos.']]);
        }

        $resultado = $planes->cambiar(
            $modelo,
            (string) $validado['nivel'],
            (int) $validado['profesionales'],
            (string) $validado['periodicidad'],
            (bool) ($validado['cobrar_diferencia'] ?? true),
        );
        $ajuste = $resultado['ajuste'];
        if ($ajuste !== null) {
            $domiciliacion->cobrar($ajuste);
            $ajuste->refresh();
        }
        Log::info('plataforma.estudio.plan', [
            'estudio' => $modelo->slug,
            'nivel' => $validado['nivel'],
            'profesionales' => (int) $validado['profesionales'],
            'periodicidad' => $validado['periodicidad'],
            'aplica' => $resultado['aplica'],
            'ajuste' => $ajuste?->ulid,
        ]);

        return response()->json(['data' => [
            'aplica' => $resultado['aplica'],
            'ajuste' => $ajuste === null ? null : [
                'id' => $ajuste->ulid,
                'monto_minor' => $ajuste->monto_minor,
                'moneda' => $ajuste->moneda,
                'estado' => $ajuste->estado->value,
            ],
            'plan' => $planes->resumen($modelo->refresh()),
        ]]);
    }

    /**
     * Suspende el acceso al estudio (todas sus rutas responden 404 mientras tanto).
     * Es una suspensión de la plataforma: no se reactiva sola al pagar.
     */
    public function suspender(Request $request, string $estudio): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();
        $validado = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);
        if (! $modelo->estado->operativo()) {
            throw ValidationException::withMessages(['estado' => ['El estudio no está activo.']]);
        }

        $modelo->update([
            'estado' => EstadoEstudio::Suspended->value,
            'suspendido_por' => SuspensionPorRenta::POR_PLATAFORMA,
            'suspendido_en' => now(),
        ]);
        Log::info('plataforma.estudio.suspendido', ['estudio' => $modelo->slug, 'motivo' => $validado['motivo'] ?? null]);

        return response()->json(['data' => self::resumen($modelo->refresh())]);
    }

    /**
     * Devuelve el acceso: vuelve a prueba si aún no termina; si no, queda activo. Si
     * estaba suspendido por renta, no se vuelve a suspender solo en otros días de
     * gracia (ADR 0073).
     */
    public function reactivar(string $estudio, SuspensionPorRenta $suspension): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();
        if ($modelo->estado !== EstadoEstudio::Suspended) {
            throw ValidationException::withMessages(['estado' => ['Solo se reactiva un estudio suspendido.']]);
        }

        $enPrueba = $modelo->trial_termina_en !== null && ! $modelo->trial_termina_en->isPast();
        $modelo->update([
            'estado' => ($enPrueba ? EstadoEstudio::Trialing : EstadoEstudio::Active)->value,
            'suspendido_por' => null,
            'suspendido_en' => null,
            'sin_suspension_hasta' => $modelo->suspendido_por === SuspensionPorRenta::POR_RENTA
                ? now()->addDays(max(1, $suspension->diasGracia()))->toDateString()
                : $modelo->sin_suspension_hasta,
        ]);
        Log::info('plataforma.estudio.reactivado', ['estudio' => $modelo->slug]);

        return response()->json(['data' => self::resumen($modelo->refresh())]);
    }

    /**
     * Extiende la prueba gratis N días (desde hoy si ya había terminado). Los meses
     * cubiertos por la prueba no generan cargo de renta.
     */
    public function extenderPrueba(Request $request, string $estudio): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();
        $validado = $request->validate(['dias' => ['required', 'integer', 'min:1', 'max:90']]);
        if (! $modelo->estado->operativo()) {
            throw ValidationException::withMessages(['estado' => ['Reactiva el estudio antes de extender su prueba.']]);
        }

        $base = $modelo->trial_termina_en !== null && ! $modelo->trial_termina_en->isPast()
            ? Carbon::parse($modelo->trial_termina_en->toDateString())
            : Carbon::today();

        $modelo->update([
            'trial_termina_en' => $base->addDays((int) $validado['dias'])->toDateString(),
            'estado' => EstadoEstudio::Trialing->value,
            'estado_facturacion' => EstadoFacturacion::Trial->value,
        ]);
        Log::info('plataforma.estudio.prueba_extendida', ['estudio' => $modelo->slug, 'dias' => $validado['dias']]);

        return response()->json(['data' => self::resumen($modelo->refresh())]);
    }

    /**
     * Activa o desactiva los avisos por WhatsApp del negocio a sus clientes (ADR
     * 0083). Solo el superadministrador lo decide, porque cada mensaje lo paga la
     * plataforma; el negocio no puede activarlo. Desactivado, sus avisos en cola se
     * descartan y ya no se ofrece a sus clientes.
     */
    public function whatsapp(Request $request, string $estudio, ClienteWhatsApp $whatsapp): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();
        $habilitado = (bool) $request->validate(['habilitado' => ['required', 'boolean']])['habilitado'];

        if ($modelo->whatsapp_habilitado !== $habilitado) {
            $modelo->forceFill(['whatsapp_habilitado' => $habilitado])->save();
            Log::info('plataforma.estudio.whatsapp', ['estudio' => $modelo->slug, 'habilitado' => $habilitado]);
        }

        return response()->json(['data' => self::whatsappClientes($modelo, $whatsapp)]);
    }

    /**
     * Cambia la modalidad del negocio, clases o citas (ADR 0104): solo mientras no
     * tenga sesiones ni reservas. `perfil_negocio` (opcional, de la nueva modalidad)
     * es su giro; sin él, conserva el suyo si encaja o toma el predeterminado.
     */
    public function modalidad(Request $request, string $estudio, CambiarModalidadEstudio $cambio): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();
        $validado = $request->validate([
            'modalidad' => ['required', Rule::enum(ModalidadServicio::class)],
            'perfil_negocio' => ['nullable', Rule::enum(PerfilNegocio::class)],
        ]);

        $modelo = $cambio->cambiar(
            $modelo,
            ModalidadServicio::from((string) $validado['modalidad']),
            isset($validado['perfil_negocio']) ? PerfilNegocio::from((string) $validado['perfil_negocio']) : null,
        );

        return response()->json(['data' => [
            ...self::resumen($modelo),
            'modalidad_cambiable' => $cambio->cambiable($modelo),
        ]]);
    }

    /**
     * @return array{habilitado: bool, plataforma: bool}
     */
    private static function whatsappClientes(Estudio $estudio, ClienteWhatsApp $whatsapp): array
    {
        return [
            'habilitado' => $estudio->whatsapp_habilitado,
            // Encendido en Configuración → WhatsApp; sin eso, ningún negocio lo usa.
            'plataforma' => $whatsapp->activoParaNegocios(),
        ];
    }

    /**
     * Terminología del negocio (ADR 0049), para ajustarla desde soporte.
     */
    public function terminologia(string $estudio, TerminologiaEstudio $terminologia): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();

        return response()->json(['data' => $terminologia->paraEditar($modelo)]);
    }

    /**
     * `valores`: {sesion|miembro|instructor: opción | null}; null vuelve al del giro.
     */
    public function guardarTerminologia(Request $request, string $estudio, TerminologiaEstudio $terminologia): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();
        $validado = $request->validate(['valores' => ['required', 'array']]);
        $cambio = $terminologia->guardar($modelo, $validado['valores']);
        Log::info('plataforma.estudio.terminologia', ['estudio' => $modelo->slug, ...$cambio]);

        return response()->json(['data' => $terminologia->paraEditar($modelo->refresh())]);
    }

    /**
     * Lo que se ve de un estudio en la lista y en la cabecera de su ficha.
     *
     * @return array<string, mixed>
     */
    public static function resumen(Estudio $e): array
    {
        return [
            'slug' => $e->slug,
            'nombre' => $e->nombre,
            'estado' => $e->estado->value,
            // Suspendido solo por renta vencida o por la plataforma (ADR 0073).
            'suspendido_por' => $e->estado === EstadoEstudio::Suspended ? $e->suspendido_por : null,
            'estado_facturacion' => $e->estado_facturacion->value,
            'perfil' => $e->perfil_negocio->value,
            'modalidad' => $e->modalidad()->value,
            'modo_cobro' => $e->modo_cobro->value,
            'cuota_fija_minor' => $e->cuota_fija_minor,
            'cuota_fija_moneda' => $e->cuota_fija_moneda,
            // Plan de un negocio de citas (ADR 0107).
            'plan' => $e->plan_nivel === null ? null : [
                'nivel' => $e->plan_nivel,
                'profesionales' => $e->plan_profesionales,
                'periodicidad' => $e->plan_periodicidad,
                'cubierto_hasta' => $e->plan_cubierto_hasta?->toDateString(),
            ],
            'domiciliado' => $e->domiciliacion_metodo !== null,
            'trial_termina_en' => $e->trial_termina_en?->toDateString(),
            'moneda' => $e->moneda,
            'publicado' => (bool) $e->publicado,
            'en_directorio' => $e->enDirectorio(),
            'pais' => $e->pais,
            'ciudad' => $e->ciudad,
            'creado_en' => $e->created_at?->toIso8601String(),
        ];
    }
}
