<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AccesoTenant;
use App\Modules\Tenancy\Models\AceptacionWaiverTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Documento;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\RespuestaFormulario;

/**
 * Derecho de acceso y portabilidad (ARCO): todos los datos que el negocio guarda de
 * una persona, en un formato legible por máquina (JSON).
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
                'recibe_promociones' => (bool) $persona->recibe_promociones,
                'acepta_whatsapp_desde' => $fecha($persona->whatsapp_aceptado_en),
                'alta' => $fecha($persona->created_at),
            ],
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
            'compras' => OrdenTenant::query()->where('persona_id', $id)->with('lineas.producto')->orderBy('id')->get()
                ->map(static fn (OrdenTenant $o): array => [
                    'estado' => $o->estado->value,
                    'total' => $o->total_minor / 100,
                    'moneda' => $o->moneda,
                    'pagada' => $fecha($o->pagada_en),
                    'conceptos' => $o->lineas->map(static fn ($l): ?string => $l->producto?->nombre)->filter()->values()->all(),
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
