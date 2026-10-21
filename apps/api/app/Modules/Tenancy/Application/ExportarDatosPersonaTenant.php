<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AccesoTenant;
use App\Modules\Tenancy\Models\AceptacionWaiverTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ApartadoArticuloTenant;
use App\Modules\Tenancy\Models\CanjeLealtadTenant;
use App\Modules\Tenancy\Models\ContextoMedicionTenant;
use App\Modules\Tenancy\Models\ConversacionTenant;
use App\Modules\Tenancy\Models\Documento;
use App\Modules\Tenancy\Models\InscripcionGrupoTenant;
use App\Modules\Tenancy\Models\InvitadoSesionTenant;
use App\Modules\Tenancy\Models\MensajeConversacionTenant;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\MovimientoPuntosTenant;
use App\Modules\Tenancy\Models\NotaPersonaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PresupuestoTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use App\Modules\Tenancy\Models\ResenaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\RespuestaFormulario;
use App\Modules\Tenancy\Models\TareaTenant;
use App\Modules\Tenancy\Models\VentaPosTenant;
use Illuminate\Support\Facades\DB;

/**
 * Derecho de acceso y portabilidad (ARCO): todos los datos que el negocio guarda de
 * una persona, en un formato legible por máquina (JSON). Incluye lo que escribió por
 * WhatsApp, Instagram o Messenger si su conversación está ligada a su ficha (ADR 0200)
 * y los identificadores de su navegador que se guardaron para medir campañas (ADR 0143).
 */
class ExportarDatosPersonaTenant
{
    /**
     * @return array<string, mixed>
     */
    public function para(PersonaTenant $persona): array
    {
        $id = $persona->getKey();
        $fecha = static fn ($valor): ?string => $valor?->toIso8601String();

        return [
            'generado_en' => now()->toIso8601String(),
            'persona' => [
                'nombre' => $persona->nombre,
                'segundo_nombre' => $persona->segundo_nombre,
                'primer_apellido' => $persona->primer_apellido,
                'segundo_apellido' => $persona->segundo_apellido,
                'email' => $persona->email,
                'celular' => $persona->celular,
                'fecha_nacimiento' => $persona->fecha_nacimiento?->toDateString(),
                'genero' => $persona->genero?->value,
                'recibe_promociones' => (bool) $persona->recibe_promociones,
                'acepta_whatsapp_desde' => $fecha($persona->whatsapp_aceptado_en),
                'como_nos_conocio' => $persona->como_nos_conocio,
                'identificador_en_sistema_anterior' => $persona->referencia_externa,
                'alta' => $fecha($persona->created_at),
            ],
            'niveles' => DB::connection('tenant')->table('personas_niveles')
                ->join('niveles', 'niveles.id', '=', 'personas_niveles.nivel_id')
                ->join('actividades', 'actividades.id', '=', 'personas_niveles.actividad_id')
                ->where('personas_niveles.persona_id', $id)
                ->get(['actividades.nombre as actividad', 'niveles.nombre as nivel'])
                ->map(static fn (object $n): array => ['actividad' => $n->actividad, 'nivel' => $n->nivel])->all(),
            'grupos' => InscripcionGrupoTenant::query()->where('persona_id', $id)->with('grupo')->orderBy('id')->get()
                ->map(static fn (InscripcionGrupoTenant $i): array => [
                    'grupo' => $i->grupo?->nombre,
                    'desde' => $i->alta_desde?->toDateString(),
                    'hasta' => $i->baja_desde?->toDateString(),
                ])->all(),
            'membresias_y_paquetes' => AcuerdoTenant::query()->where('persona_id', $id)->with('producto')->get()
                ->map(static fn (AcuerdoTenant $a): array => [
                    'producto' => $a->producto?->nombre,
                    'estado' => $a->estado->value,
                    'inicio' => $a->fecha_inicio->toDateString(),
                    'proximo_cobro' => $a->proxima_cobro_en?->toDateString(),
                ])->all(),
            'movimientos_de_creditos' => MovimientoCreditoTenant::query()->where('persona_id', $id)->orderBy('id')->get()
                ->map(static fn (MovimientoCreditoTenant $m): array => [
                    'tipo' => $m->tipo->value,
                    'creditos' => $m->unidades / 1000,
                    'descripcion' => $m->descripcion,
                    'fecha' => $fecha($m->created_at),
                ])->all(),
            'reservas' => ReservaTenant::query()->where('persona_id', $id)->with(['sesion.oferta', 'asistencia'])->orderBy('id')->get()
                ->map(static fn (ReservaTenant $r): array => [
                    'actividad' => $r->sesion?->oferta?->nombre,
                    'inicia' => $fecha($r->sesion?->inicia_en),
                    'estado' => $r->estado->value,
                    'asistencia' => $r->asistencia?->estado->value,
                ])->all(),
            'invitados_a_clases' => InvitadoSesionTenant::query()->where('anfitrion_id', $id)->with('sesion.oferta')->orderBy('id')->get()
                ->map(static fn (InvitadoSesionTenant $i): array => [
                    'invitado' => $i->nombre,
                    'actividad' => $i->sesion?->oferta?->nombre,
                    'inicia' => $fecha($i->sesion?->inicia_en),
                    'estado' => $i->estado,
                ])->all(),
            'resenas' => ResenaTenant::query()->where('persona_id', $id)->with('oferta')->orderBy('id')->get()
                ->map(static fn (ResenaTenant $r): array => [
                    'actividad' => $r->oferta?->nombre,
                    'calificacion' => (int) $r->calificacion,
                    'comentario' => $r->comentario,
                    'fecha' => $fecha($r->created_at),
                ])->all(),
            'compras' => OrdenTenant::query()->where('persona_id', $id)->with('lineas.producto')->orderBy('id')->get()
                ->map(static fn (OrdenTenant $o): array => [
                    'estado' => $o->estado->value,
                    'total' => $o->total_minor / 100,
                    'moneda' => $o->moneda,
                    'pagada' => $fecha($o->pagada_en),
                    'conceptos' => $o->lineas->map(static fn ($l): string => $l->nombre())->filter()->values()->all(),
                ])->all(),
            'compras_en_mostrador' => VentaPosTenant::query()->where('persona_id', $id)->with('lineas.articulo')->orderBy('id')->get()
                ->map(static fn (VentaPosTenant $v): array => [
                    'total' => $v->total_minor / 100,
                    'moneda' => $v->moneda,
                    'fecha' => $fecha($v->created_at),
                    'anulada' => $fecha($v->anulada_en),
                    'conceptos' => $v->lineas->map(static fn ($l): string => (string) $l->articulo?->nombre)->filter()->values()->all(),
                ])->all(),
            'productos_apartados' => ApartadoArticuloTenant::query()->where('persona_id', $id)->with('articulo')->orderBy('id')->get()
                ->map(static fn (ApartadoArticuloTenant $a): array => [
                    'articulo' => $a->articulo?->nombre,
                    'cantidad' => (int) $a->cantidad,
                    'estado' => $a->estado,
                    'fecha' => $fecha($a->created_at),
                ])->all(),
            'presupuestos' => PresupuestoTenant::query()->where('persona_id', $id)->orderBy('id')->get()
                ->map(static fn (PresupuestoTenant $p): array => [
                    'folio' => $p->folio,
                    'estado' => $p->estado->value,
                    'total' => $p->total_minor / 100,
                    'moneda' => $p->moneda,
                    'contacto' => array_filter(['nombre' => $p->nombre_contacto, 'email' => $p->email_contacto, 'celular' => $p->celular_contacto]),
                    'fecha' => $fecha($p->created_at),
                ])->all(),
            'puntos' => MovimientoPuntosTenant::query()->where('persona_id', $id)->orderBy('id')->get()
                ->map(static fn (MovimientoPuntosTenant $m): array => [
                    'puntos' => (int) $m->puntos,
                    'descripcion' => $m->descripcion,
                    'fecha' => $fecha($m->created_at),
                ])->all(),
            'canjes' => CanjeLealtadTenant::query()->where('persona_id', $id)->orderBy('id')->get()
                ->map(static fn (CanjeLealtadTenant $c): array => [
                    'recompensa' => $c->recompensa_nombre,
                    'puntos' => (int) $c->puntos,
                    'estado' => $c->estado->value,
                    'fecha' => $fecha($c->created_at),
                ])->all(),
            // Su solicitud de informes, si llegó como prospecto y se convirtió.
            'solicitudes_de_informes' => ProspectoTenant::query()->where('persona_id', $id)->orderBy('id')->get()
                ->map(static fn (ProspectoTenant $p): array => [
                    'nombre' => $p->nombre,
                    'email' => $p->email,
                    'telefono' => $p->telefono,
                    'respuestas' => $p->respuestas,
                    'fecha' => $fecha($p->created_at),
                ])->all(),
            'seguimientos' => TareaTenant::query()->where('persona_id', $id)->orderBy('id')->get()
                ->map(static fn (TareaTenant $t): array => [
                    'titulo' => $t->titulo,
                    'estado' => $t->estado->value,
                    'fecha' => $fecha($t->created_at),
                ])->all(),
            // Lo que escribió y le contestaron por WhatsApp, Instagram o Messenger.
            'conversaciones' => ConversacionTenant::query()->where('persona_id', $id)->orderBy('id')->get()
                ->map(static fn (ConversacionTenant $c): array => [
                    'canal' => $c->canal,
                    'contacto' => $c->contacto_nombre,
                    'mensajes' => MensajeConversacionTenant::query()->where('conversacion_id', $c->getKey())->orderBy('id')->get()
                        ->map(static fn (MensajeConversacionTenant $m): array => [
                            'de' => $m->direccion === MensajeConversacionTenant::ENTRANTE ? 'persona' : 'negocio',
                            'texto' => $m->texto,
                            'archivo' => $m->adjunto_nombre,
                            'fecha' => $fecha($m->enviado_en ?? $m->created_at),
                        ])->all(),
                ])->all(),
            // Lo que su navegador permitió guardar para medir campañas (ADR 0143).
            'medicion_de_campanas' => ContextoMedicionTenant::query()->where('persona_id', $id)->orderBy('id')->get()
                ->map(static fn (ContextoMedicionTenant $c): array => array_filter([
                    'fuente' => $c->fuente,
                    'medio' => $c->medio,
                    'campana' => $c->campana,
                    'autorizo_analitica' => (bool) $c->analitica,
                    'autorizo_publicidad' => (bool) $c->publicidad,
                    'identificador_del_navegador' => $c->visitante,
                    'ip' => $c->ip,
                    'navegador' => $c->agente,
                    'fecha' => $fecha($c->capturado_en ?? $c->created_at),
                ], static fn ($v): bool => $v !== null))->all(),
            // Lo que el equipo anotó de la persona (también son sus datos).
            'notas_del_negocio' => NotaPersonaTenant::query()->where('persona_id', $id)->orderBy('id')->get()
                ->map(static fn (NotaPersonaTenant $n): array => [
                    'texto' => $n->texto,
                    'fecha' => $fecha($n->created_at),
                ])->all(),
            'accesos' => AccesoTenant::query()->where('persona_id', $id)->orderBy('id')->get()
                ->map(static fn (AccesoTenant $a): array => [
                    'fecha' => $fecha($a->registrado_en),
                    'resultado' => $a->resultado->value,
                ])->all(),
            'documentos' => Documento::query()->where('persona_id', $id)->with('tipo')->get()
                ->map(static fn (Documento $d): array => [
                    'tipo' => $d->tipo?->nombre,
                    'archivo' => $d->nombre,
                    'estado' => $d->estado->value,
                    'subido' => $fecha($d->subido_en),
                ])->all(),
            'consentimientos' => AceptacionWaiverTenant::query()->where('persona_id', $id)->with('waiver')->get()
                ->map(static fn (AceptacionWaiverTenant $a): array => [
                    'documento' => $a->waiver?->titulo,
                    'aceptado' => $fecha($a->aceptado_en),
                ])->all(),
            'formularios' => RespuestaFormulario::query()->where('persona_id', $id)->with('formulario')->get()
                ->map(static fn (RespuestaFormulario $r): array => [
                    'formulario' => $r->formulario?->nombre,
                    'respuestas' => $r->valores,
                    'fecha' => $fecha($r->created_at),
                ])->all(),
            'mensajes' => MensajeTenant::query()->where('persona_id', $id)->orderBy('id')->get()
                ->map(static fn (MensajeTenant $m): array => [
                    'canal' => $m->canal->value,
                    'asunto' => $m->asunto,
                    'fecha' => $fecha($m->created_at),
                ])->all(),
        ];
    }
}
