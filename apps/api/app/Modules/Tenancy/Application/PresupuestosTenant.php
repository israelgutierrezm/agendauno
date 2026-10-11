<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\PresupuestoNoPermitido;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\ConfiguracionPresupuestosTenant;
use App\Modules\Tenancy\Models\LineaPresupuestoTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PresupuestoTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Presupuestos\EstadoPresupuesto;
use App\Modules\Tenancy\Presupuestos\ModoImpuestos;
use App\Modules\Tenancy\Presupuestos\TipoLineaPresupuesto;
use App\Modules\Tenancy\Presupuestos\TotalesPresupuesto;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Support\Facades\DB;

/**
 * Presupuestos de los negocios de citas (ADR 0127).
 *
 * - Se crean como borrador con un folio consecutivo del negocio y los impuestos de su
 *   configuración en ese momento (quedan congelados en el presupuesto).
 * - Los conceptos salen del catálogo (servicios, paquetes/bonos/membresías, artículos)
 *   o se escriben a mano; el precio se puede ajustar y cada concepto puede llevar
 *   descuento. Las cuentas, en centavos ({@see TotalesPresupuesto}).
 * - Se editan mientras no los responda el cliente. El negocio marca si el cliente lo
 *   aceptó (vigente) o rechazó.
 * - Aceptado, se convierte en una orden de cobro con sus conceptos: el plan vendido se
 *   concede al pagarse y el artículo sale del inventario al pagarse; si era para un
 *   contacto que aún no es cliente, se da de alta.
 */
class PresupuestosTenant
{
    public function __construct(
        private readonly RegionNegocioTenant $region,
        private readonly FechasNegocioTenant $fechas,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function configuracion(): ConfiguracionPresupuestosTenant
    {
        return ConfiguracionPresupuestosTenant::query()->firstOrCreate([], [
            'impuestos' => ModoImpuestos::Incluidos->value,
            'tasa_impuesto_bp' => 1600,
            'vigencia_dias' => 15,
            'ultimo_folio' => 0,
        ]);
    }

    /**
     * @param  array{impuestos: string, tasa_impuesto_bp: int, vigencia_dias: int, notas?: string|null}  $datos
     */
    public function guardarConfiguracion(array $datos, ?Usuario $actor): ConfiguracionPresupuestosTenant
    {
        $config = $this->configuracion();
        $antes = $config->only(['impuestos', 'tasa_impuesto_bp', 'vigencia_dias']);
        $config->fill([
            'impuestos' => $datos['impuestos'],
            'tasa_impuesto_bp' => $datos['tasa_impuesto_bp'],
            'vigencia_dias' => $datos['vigencia_dias'],
            'notas' => $datos['notas'] ?? null,
        ])->save();
        $this->auditoria->registrar($actor, 'presupuestos.configuracion', 'configuracion_presupuestos', null, $antes, $config->only(['impuestos', 'tasa_impuesto_bp', 'vigencia_dias']));

        return $config;
    }

    /**
     * @param  array<string, mixed>  $datos  cliente (persona_id o contacto), sucursal_id, valido_hasta, descuento_minor, notas y lineas
     */
    public function crear(array $datos, ?Usuario $actor): PresupuestoTenant
    {
        $lineas = $this->normalizarLineas($datos['lineas'] ?? []);

        return DB::connection('tenant')->transaction(function () use ($datos, $lineas, $actor): PresupuestoTenant {
            // El folio, consecutivo por negocio: se bloquea la configuración para no repetirlo.
            $config = ConfiguracionPresupuestosTenant::query()->whereKey($this->configuracion()->getKey())->lockForUpdate()->firstOrFail();
            $config->ultimo_folio++;
            $config->save();

            $presupuesto = new PresupuestoTenant([
                'folio' => $config->ultimo_folio,
                'estado' => EstadoPresupuesto::Borrador->value,
                'moneda' => $this->region->moneda(),
                'impuestos' => $config->impuestos->value,
                'tasa_impuesto_bp' => $config->tasa_impuesto_bp,
                'valido_hasta' => $datos['valido_hasta'] ?? $this->fechas->dia()->addDays($config->vigencia_dias)->toDateString(),
                'notas' => array_key_exists('notas', $datos) ? $datos['notas'] : $config->notas,
                'creado_por_id' => $actor?->getKey(),
            ]);
            $this->asignarCliente($presupuesto, $datos);
            $presupuesto->sucursal_id = $datos['sucursal_id'] ?? null;
            $this->guardarLineas($presupuesto, $lineas, (int) ($datos['descuento_minor'] ?? 0));
            $this->auditoria->registrar($actor, 'presupuesto.creado', 'presupuesto', (string) $presupuesto->ulid, null, ['folio' => $presupuesto->folio, 'total_minor' => $presupuesto->total_minor]);

            return $presupuesto;
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(PresupuestoTenant $presupuesto, array $datos, ?Usuario $actor): PresupuestoTenant
    {
        $this->exigirEditable($presupuesto);
        $lineas = $this->normalizarLineas($datos['lineas'] ?? []);

        return DB::connection('tenant')->transaction(function () use ($presupuesto, $datos, $lineas, $actor): PresupuestoTenant {
            $antes = ['total_minor' => $presupuesto->total_minor, 'valido_hasta' => $presupuesto->valido_hasta->toDateString()];
            $this->asignarCliente($presupuesto, $datos);
            $presupuesto->sucursal_id = $datos['sucursal_id'] ?? null;
            if (isset($datos['valido_hasta'])) {
                $presupuesto->setAttribute('valido_hasta', (string) $datos['valido_hasta']);
            }
            if (array_key_exists('notas', $datos)) {
                $presupuesto->notas = $datos['notas'];
            }
            $presupuesto->lineas()->delete();
            $this->guardarLineas($presupuesto, $lineas, (int) ($datos['descuento_minor'] ?? 0));
            $this->auditoria->registrar($actor, 'presupuesto.editado', 'presupuesto', (string) $presupuesto->ulid, $antes, ['total_minor' => $presupuesto->total_minor, 'valido_hasta' => $presupuesto->valido_hasta->toDateString()]);

            return $presupuesto;
        });
    }

    public function marcarEnviado(PresupuestoTenant $presupuesto): void
    {
        if ($presupuesto->estado === EstadoPresupuesto::Borrador) {
            $presupuesto->estado = EstadoPresupuesto::Enviado;
        }
        $presupuesto->enviado_en = now();
        $presupuesto->save();
    }

    public function aceptar(PresupuestoTenant $presupuesto, ?Usuario $actor): PresupuestoTenant
    {
        $this->exigirEditable($presupuesto);
        if ($this->vencido($presupuesto)) {
            throw new PresupuestoNoPermitido('Este presupuesto ya venció: cambia su vigencia antes de marcarlo aceptado.', 'vencido');
        }
        $presupuesto->fill(['estado' => EstadoPresupuesto::Aceptado->value, 'respondido_en' => now(), 'motivo_rechazo' => null])->save();
        $this->auditoria->registrar($actor, 'presupuesto.aceptado', 'presupuesto', (string) $presupuesto->ulid, null, ['total_minor' => $presupuesto->total_minor]);

        return $presupuesto;
    }

    public function rechazar(PresupuestoTenant $presupuesto, ?string $motivo, ?Usuario $actor): PresupuestoTenant
    {
        $this->exigirEditable($presupuesto);
        $presupuesto->fill(['estado' => EstadoPresupuesto::Rechazado->value, 'respondido_en' => now(), 'motivo_rechazo' => $motivo])->save();
        $this->auditoria->registrar($actor, 'presupuesto.rechazado', 'presupuesto', (string) $presupuesto->ulid, null, null, $motivo);

        return $presupuesto;
    }

    /**
     * Convierte un presupuesto aceptado en una orden de cobro pendiente, con sus
     * conceptos al precio del presupuesto. Devuelve la orden.
     */
    public function convertir(PresupuestoTenant $presupuesto, ?Usuario $actor): OrdenTenant
    {
        return DB::connection('tenant')->transaction(function () use ($presupuesto, $actor): OrdenTenant {
            $bloqueado = PresupuestoTenant::query()->whereKey($presupuesto->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado !== EstadoPresupuesto::Aceptado) {
                throw new PresupuestoNoPermitido('Solo un presupuesto aceptado se convierte en cobro.', 'no_aceptado');
            }

            $cliente = $this->clienteDe($bloqueado);
            $orden = OrdenTenant::query()->create([
                'persona_id' => $cliente->getKey(),
                'sucursal_id' => $bloqueado->sucursal_id,
                'estado' => EstadoOrden::Pendiente->value,
                'total_minor' => $bloqueado->total_minor,
                'descuento_minor' => $bloqueado->descuento_minor,
                'moneda' => $bloqueado->moneda,
            ]);
            foreach ($bloqueado->lineas as $linea) {
                $orden->lineas()->create([
                    'producto_comercial_id' => $linea->tipo === TipoLineaPresupuesto::Producto ? $linea->referencia_id : null,
                    'articulo_id' => $linea->tipo === TipoLineaPresupuesto::Articulo ? $linea->referencia_id : null,
                    'descripcion' => $linea->tipo === TipoLineaPresupuesto::Producto ? null : $linea->descripcion,
                    'cantidad' => $linea->cantidad,
                    'precio_unitario_minor' => $linea->precio_unitario_minor,
                    'subtotal_minor' => $linea->subtotal_minor,
                ]);
            }
            // Desglosados: el impuesto va como su propio concepto, para que la orden sume
            // lo mismo que el presupuesto.
            if ($bloqueado->impuestos === ModoImpuestos::Desglosados && $bloqueado->impuesto_minor > 0) {
                $orden->lineas()->create([
                    'descripcion' => 'Impuestos ('.self::porcentaje($bloqueado->tasa_impuesto_bp).')',
                    'cantidad' => 1,
                    'precio_unitario_minor' => $bloqueado->impuesto_minor,
                    'subtotal_minor' => $bloqueado->impuesto_minor,
                ]);
            }

            $bloqueado->fill([
                'estado' => EstadoPresupuesto::Convertido->value,
                'orden_id' => $orden->getKey(),
                'persona_id' => $cliente->getKey(),
            ])->save();
            $this->auditoria->registrar($actor, 'presupuesto.convertido', 'presupuesto', (string) $bloqueado->ulid, null, ['orden' => $orden->ulid, 'total_minor' => $orden->total_minor]);

            return $orden;
        });
    }

    public function eliminar(PresupuestoTenant $presupuesto, ?Usuario $actor): void
    {
        if ($presupuesto->estado !== EstadoPresupuesto::Borrador) {
            throw new PresupuestoNoPermitido('Solo se borra un presupuesto en borrador; uno enviado se marca rechazado.', 'no_borrador');
        }
        DB::connection('tenant')->transaction(function () use ($presupuesto, $actor): void {
            $presupuesto->lineas()->delete();
            $presupuesto->delete();
            $this->auditoria->registrar($actor, 'presupuesto.eliminado', 'presupuesto', (string) $presupuesto->ulid, ['folio' => $presupuesto->folio], null);
        });
    }

    /** ¿Su vigencia ya pasó sin que lo respondiera el cliente? */
    public function vencido(PresupuestoTenant $presupuesto): bool
    {
        return $presupuesto->estado->editable()
            && $presupuesto->valido_hasta->toDateString() < $this->fechas->hoy();
    }

    public static function porcentaje(int $tasaBp): string
    {
        $entero = intdiv($tasaBp, 100);
        $resto = $tasaBp % 100;

        return ($resto === 0 ? (string) $entero : $entero.'.'.rtrim(str_pad((string) $resto, 2, '0', STR_PAD_LEFT), '0')).' %';
    }

    private function exigirEditable(PresupuestoTenant $presupuesto): void
    {
        if (! $presupuesto->estado->editable()) {
            throw new PresupuestoNoPermitido('Este presupuesto ya se respondió: ya no se cambia.', 'respondido');
        }
    }

    /**
     * Para un cliente (persona) o para un contacto que aún no lo es.
     *
     * @param  array<string, mixed>  $datos
     */
    private function asignarCliente(PresupuestoTenant $presupuesto, array $datos): void
    {
        $personaId = $datos['persona_id'] ?? null;
        if ($personaId !== null) {
            $presupuesto->persona_id = (int) $personaId;
            $presupuesto->nombre_contacto = null;
            $presupuesto->email_contacto = null;
            $presupuesto->celular_contacto = null;

            return;
        }
        $nombre = trim((string) ($datos['nombre_contacto'] ?? ''));
        if ($nombre === '') {
            throw new PresupuestoNoPermitido('Elige al cliente o escribe el nombre de a quién va el presupuesto.', 'sin_cliente');
        }
        $presupuesto->persona_id = null;
        $presupuesto->nombre_contacto = $nombre;
        $presupuesto->email_contacto = ($e = trim((string) ($datos['email_contacto'] ?? ''))) !== '' ? $e : null;
        $presupuesto->celular_contacto = ($c = trim((string) ($datos['celular_contacto'] ?? ''))) !== '' ? $c : null;
    }

    /**
     * @param  list<array{tipo: TipoLineaPresupuesto, referencia_id: int|null, descripcion: string, cantidad: int, precio_unitario_minor: int, descuento_minor: int}>  $lineas
     */
    private function guardarLineas(PresupuestoTenant $presupuesto, array $lineas, int $descuentoMinor): void
    {
        $subtotales = array_map(static fn (array $l): int => TotalesPresupuesto::linea($l['cantidad'], $l['precio_unitario_minor'], $l['descuento_minor']), $lineas);
        $totales = TotalesPresupuesto::calcular($subtotales, $descuentoMinor, $presupuesto->impuestos, $presupuesto->tasa_impuesto_bp);
        $presupuesto->fill([
            'subtotal_minor' => $totales->subtotalMinor,
            'descuento_minor' => $totales->descuentoMinor,
            'impuesto_minor' => $totales->impuestoMinor,
            'total_minor' => $totales->totalMinor,
        ])->save();

        foreach ($lineas as $i => $linea) {
            LineaPresupuestoTenant::query()->create([
                'presupuesto_id' => $presupuesto->getKey(),
                'tipo' => $linea['tipo']->value,
                'referencia_id' => $linea['referencia_id'],
                'descripcion' => $linea['descripcion'],
                'cantidad' => $linea['cantidad'],
                'precio_unitario_minor' => $linea['precio_unitario_minor'],
                'descuento_minor' => $linea['descuento_minor'],
                'subtotal_minor' => $subtotales[$i],
                'posicion' => $i + 1,
            ]);
        }
        $presupuesto->unsetRelation('lineas');
    }

    /**
     * Cada concepto con su origen resuelto: del catálogo toma el nombre y el precio si
     * no se escribieron; uno libre necesita descripción.
     *
     * @param  array<int, mixed>  $entrada
     * @return list<array{tipo: TipoLineaPresupuesto, referencia_id: int|null, descripcion: string, cantidad: int, precio_unitario_minor: int, descuento_minor: int}>
     */
    private function normalizarLineas(array $entrada): array
    {
        if ($entrada === []) {
            throw new PresupuestoNoPermitido('Agrega al menos un concepto al presupuesto.', 'sin_conceptos');
        }
        $lineas = [];
        foreach ($entrada as $cruda) {
            $tipo = TipoLineaPresupuesto::from((string) $cruda['tipo']);
            [$referenciaId, $nombre, $precio] = $this->delCatalogo($tipo, $cruda['referencia'] ?? null);
            $descripcion = trim((string) ($cruda['descripcion'] ?? '')) !== '' ? trim((string) $cruda['descripcion']) : $nombre;
            if ($descripcion === null || $descripcion === '') {
                throw new PresupuestoNoPermitido('Cada concepto escrito a mano necesita su descripción.', 'sin_descripcion');
            }
            $lineas[] = [
                'tipo' => $tipo,
                'referencia_id' => $referenciaId,
                'descripcion' => mb_substr($descripcion, 0, 255),
                'cantidad' => max(1, (int) ($cruda['cantidad'] ?? 1)),
                'precio_unitario_minor' => isset($cruda['precio_unitario_minor']) ? max(0, (int) $cruda['precio_unitario_minor']) : (int) $precio,
                'descuento_minor' => max(0, (int) ($cruda['descuento_minor'] ?? 0)),
            ];
        }

        return $lineas;
    }

    /**
     * @return array{0: int|null, 1: string|null, 2: int}
     */
    private function delCatalogo(TipoLineaPresupuesto $tipo, mixed $referencia): array
    {
        if ($tipo === TipoLineaPresupuesto::Libre) {
            return [null, null, 0];
        }
        $ulid = (string) $referencia;
        $modelo = match ($tipo) {
            TipoLineaPresupuesto::Servicio => OfertaTenant::query()->where('ulid', $ulid)->first(),
            TipoLineaPresupuesto::Producto => ProductoTenant::query()->where('ulid', $ulid)->first(),
            TipoLineaPresupuesto::Articulo => ArticuloTenant::query()->where('ulid', $ulid)->first(),
        };
        if ($modelo === null) {
            throw new PresupuestoNoPermitido('Uno de los conceptos ya no está en el catálogo.', 'concepto_inexistente');
        }

        return match (true) {
            $modelo instanceof OfertaTenant => [(int) $modelo->getKey(), (string) $modelo->nombre, (int) $modelo->precio_clase_minor],
            $modelo instanceof ProductoTenant => [(int) $modelo->getKey(), (string) $modelo->nombre, (int) $modelo->precio_minor],
            default => [(int) $modelo->getKey(), (string) $modelo->nombre, (int) $modelo->precio_minor],
        };
    }

    /** El cliente del presupuesto; si era un contacto, se da de alta como cliente. */
    private function clienteDe(PresupuestoTenant $presupuesto): PersonaTenant
    {
        $persona = $presupuesto->persona;
        if ($persona instanceof PersonaTenant) {
            return $persona;
        }
        $email = $presupuesto->email_contacto;
        $existente = $email !== null
            ? PersonaTenant::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first()
            : null;

        return $existente ?? PersonaTenant::query()->create([
            'nombre' => (string) $presupuesto->nombre_contacto,
            'email' => $email,
            'celular' => $presupuesto->celular_contacto,
            'tipo' => TipoPersonaTenant::Miembro->value,
            'activo' => true,
            'es_facturable' => true,
            'archivado' => false,
        ]);
    }
}
