<?php

declare(strict_types=1);

namespace App\Console\Commands\Demos;

use App\Modules\Tenancy\Application\BajaDePersonaTenant;
use App\Modules\Tenancy\Application\DifundirComunicacionTenant;
use App\Modules\Tenancy\Application\EmitirFacturaTenant;
use App\Modules\Tenancy\Application\GestionarLlavesApiTenant;
use App\Modules\Tenancy\Application\GestionarPromocionesTenant;
use App\Modules\Tenancy\Application\GestionarTareasTenant;
use App\Modules\Tenancy\Application\PuntosTenant;
use App\Modules\Tenancy\Application\ReembolsarPagoTenant;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Automatizacion\AccionAutomatizacion;
use App\Modules\Tenancy\Automatizacion\EventoAutomatizacion;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Comunicaciones\SegmentoComunicacion;
use App\Modules\Tenancy\EstadoDocumento;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Listeners\AcumularPuntos;
use App\Modules\Tenancy\Models\CampoFormulario;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\Documento;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\Formulario;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\NotaPersonaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProgramaLealtadTenant;
use App\Modules\Tenancy\Models\RecompensaLealtadTenant;
use App\Modules\Tenancy\Models\ReglaAutomatizacionTenant;
use App\Modules\Tenancy\Models\RespuestaFormulario;
use App\Modules\Tenancy\Models\TipoDocumento;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Ordenes\TipoPromocion;
use App\Modules\Tenancy\TipoCampo;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Lo que la historia del día a día no toca y un negocio en marcha sí tiene: tareas del
 * equipo, notas en las fichas, expedientes (documentos y consentimiento), un
 * formulario con respuestas, promociones, el programa de lealtad con sus puntos y
 * canjes, comunicaciones enviadas, facturas, una devolución y una solicitud de
 * privacidad. Cada demo pone su contenido; aquí se siembra con los servicios del
 * dominio, al terminar la historia y con el reloj en el momento de cada cosa.
 *
 * @phpstan-require-extends DemoBase
 */
trait ApartadosDemo
{
    /**
     * Tareas del equipo: unas hechas, otras por hacer (alguna ya vencida).
     *
     * @param  list<array{0: string, 1: string|null, 2: int, 3: Usuario|null, 4: bool, 5?: PersonaTenant|null}>  $tareas  [título, detalle, vence (días desde hoy), responsable, hecha, persona]
     */
    protected function tareasDelEquipo(array $tareas, Usuario $quienCompleta): void
    {
        $servicio = app(GestionarTareasTenant::class);
        foreach ($tareas as $t) {
            [$titulo, $detalle, $dias, $responsable, $hecha] = $t;
            $creada = $this->en($this->hoy->addDays(min(0, $dias) - $this->azar(2, 6))->setTime($this->azar(9, 12), $this->azar(0, 59)));
            $tarea = $servicio->crear([
                'titulo' => $titulo,
                'detalle' => $detalle,
                'persona_id' => ($t[5] ?? null)?->getKey(),
                'responsable_id' => $responsable?->getKey(),
                'vence_en' => $this->hoy->addDays($dias)->setTime(19, 0)->utc(),
            ]);
            if ($hecha) {
                $this->en($creada->addHours($this->azar(3, 40)));
                $servicio->completar($tarea, $responsable ?? $quienCompleta);
            }
            $this->sumar('tareas');
        }
    }

    /**
     * Notas del equipo en las fichas de los clientes.
     *
     * @param  list<string>  $textos
     * @param  list<Usuario>  $autores
     */
    protected function notasEnFichas(array $textos, array $autores): void
    {
        $ids = $this->idsDeClientes();
        foreach ($textos as $texto) {
            $this->en($this->hoy->subDays($this->azar(1, 70))->setTime($this->azar(10, 20), $this->azar(0, 59)));
            NotaPersonaTenant::query()->create([
                'persona_id' => $this->uno($ids), 'autor_id' => $this->uno($autores)->getKey(), 'texto' => $texto,
            ]);
            $this->sumar('notas en fichas');
        }
    }

    /**
     * Los documentos que pide el negocio y los que subieron sus clientes (revisados,
     * por revisar y alguno rechazado), más su consentimiento vigente y quién lo aceptó.
     *
     * @param  list<array{0: string, 1: string, 2: bool}>  $tipos  [nombre, para qué, obligatorio]
     * @param  array{0: string, 1: string, 2: string}  $consentimiento  [clave, título, texto]
     */
    protected function expedientes(array $tipos, array $consentimiento, Usuario $revisa, int $documentos, int $porcientoAcepta): void
    {
        $modelos = [];
        foreach ($tipos as [$nombre, $descripcion, $obligatorio]) {
            $modelos[] = TipoDocumento::query()->create([
                'nombre' => $nombre, 'descripcion' => $descripcion, 'obligatorio' => $obligatorio, 'aplica_a' => 'miembro', 'activo' => true,
            ]);
        }

        $personas = PersonaTenant::query()->whereIn('id', $this->idsDeClientes())->orderBy('id')->get()->all();
        foreach (array_slice($this->conCuentaPrimero($personas), 0, $documentos) as $persona) {
            $tipo = $this->uno($modelos);
            $subido = $this->en($this->despuesDelAlta($persona, 1, 60));
            $archivo = 'documentos/'.$this->estudio->getKey().'/'.Str::random(40).'.pdf';
            Storage::disk('local')->put($archivo, $this->pdf($tipo->nombre, $persona->nombreCompleto()));
            $estado = (string) $this->elegir([EstadoDocumento::Aprobado->value => 70, EstadoDocumento::Pendiente->value => 24, EstadoDocumento::Rechazado->value => 6]);
            $documento = Documento::query()->create([
                'persona_id' => $persona->getKey(), 'tipo_documento_id' => $tipo->getKey(),
                'nombre' => $tipo->nombre.' - '.$persona->nombreCompleto().'.pdf', 'ruta' => $archivo, 'mime' => 'application/pdf',
                'estado' => EstadoDocumento::Pendiente->value, 'subido_en' => Carbon::now(),
            ]);
            if ($estado !== EstadoDocumento::Pendiente->value) {
                $documento->update([
                    'estado' => $estado, 'validado_por' => $revisa->getKey(), 'validado_en' => $subido->addHours($this->azar(2, 30))->min($this->ahora)->utc(),
                    'motivo' => $estado === EstadoDocumento::Rechazado->value ? 'La imagen no se lee; súbelo de nuevo, por favor.' : null,
                ]);
            }
            $this->sumar('documentos en expedientes');
        }

        [$clave, $titulo, $texto] = $consentimiento;
        $this->en($this->inicio->subDays(20)->setTime(10, 0));
        $waivers = app(WaiversTenant::class);
        $waiver = $waivers->publicar($clave, $titulo, $texto);
        foreach ($personas as $persona) {
            if ($this->prob($porcientoAcepta)) {
                $this->en($this->despuesDelAlta($persona, 0, 2));
                $waivers->aceptar($persona, $waiver, null);
                $this->sumar('consentimientos aceptados');
            }
        }
    }

    /**
     * Un formulario del negocio y las respuestas de algunos clientes. En texto y
     * número, la lista son respuestas de ejemplo; en selección, sus opciones.
     *
     * @param  list<array{0: string, 1: TipoCampo, 2: bool, 3?: list<string>}>  $campos  [etiqueta, tipo, obligatorio, opciones o ejemplos]
     */
    protected function formularioConRespuestas(string $nombre, string $descripcion, array $campos, int $respuestas): void
    {
        $this->en($this->inicio->subDays(15)->setTime(11, 0));
        $formulario = Formulario::query()->create(['nombre' => $nombre, 'descripcion' => $descripcion, 'aplica_a' => 'miembro', 'activo' => true]);
        $modelos = [];
        foreach ($campos as $orden => $c) {
            $modelos[] = [CampoFormulario::query()->create([
                'formulario_id' => $formulario->getKey(), 'etiqueta' => $c[0], 'tipo' => $c[1]->value, 'obligatorio' => $c[2],
                'opciones' => $c[1] === TipoCampo::Seleccion ? ($c[3] ?? []) : null, 'orden' => $orden + 1,
            ]), $c];
        }

        $personas = PersonaTenant::query()->whereIn('id', $this->idsDeClientes())->orderBy('id')->get()->all();
        foreach (array_slice($this->conCuentaPrimero($personas), 0, $respuestas) as $persona) {
            $valores = [];
            foreach ($modelos as [$campo, $c]) {
                if (! $c[2] && $this->prob(30)) {
                    continue;
                }
                $valores[(string) $campo->ulid] = match ($c[1]) {
                    TipoCampo::Booleano => $this->prob(20),
                    TipoCampo::Fecha => $this->hoy->subDays($this->azar(30, 900))->toDateString(),
                    default => $this->uno($c[3] ?? ['Sin comentarios']),
                };
            }
            $this->en($this->despuesDelAlta($persona, 0, 3));
            RespuestaFormulario::query()->create(['formulario_id' => $formulario->getKey(), 'persona_id' => $persona->getKey(), 'valores' => $valores]);
            $this->sumar('respuestas del formulario');
        }
    }

    /**
     * Códigos de descuento: vigentes, alguno ya usado varias veces y uno vencido.
     *
     * @param  list<array{0: string, 1: string, 2: TipoPromocion, 3: int, 4: int|null, 5: int|null, 6: int, 7: int|null, 8: bool}>  $promociones  [código, descripción, tipo, valor (bps o centavos), mínimo, usos máximos, usos, vence (días desde hoy), activa]
     */
    protected function promociones(array $promociones): void
    {
        $servicio = app(GestionarPromocionesTenant::class);
        foreach ($promociones as [$codigo, $descripcion, $tipo, $valor, $minimo, $maximos, $usos, $vence, $activa]) {
            $this->en($this->inicio->addDays($this->azar(0, 20))->setTime(12, 0));
            $servicio->crear([
                'codigo' => $codigo, 'descripcion' => $descripcion, 'tipo' => $tipo->value, 'valor' => $valor,
                'monto_minimo_minor' => $minimo, 'usos_maximos' => $maximos, 'usos' => $usos,
                'vence_en' => $vence === null ? null : $this->hoy->addDays($vence)->endOfDay()->utc(), 'activa' => $activa,
            ]);
            $this->sumar('promociones');
        }
    }

    /**
     * El programa de lealtad activo desde el principio: los puntos de cada asistencia
     * y cada compra de la historia (con el mismo listener que el relay, en el momento
     * de cada evento), sus recompensas y algunos canjes.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $recompensas  [nombre, descripción, puntos]
     */
    protected function lealtad(int $porAsistencia, int $porMoneda, array $recompensas, Usuario $entrega): void
    {
        ProgramaLealtadTenant::query()->updateOrCreate([], [
            'activa' => true, 'puntos_por_asistencia' => $porAsistencia, 'puntos_por_moneda' => $porMoneda,
        ]);
        $premios = [];
        foreach ($recompensas as [$nombre, $descripcion, $puntos]) {
            $premios[] = RecompensaLealtadTenant::query()->create(['nombre' => $nombre, 'descripcion' => $descripcion, 'costo_puntos' => $puntos, 'activa' => true]);
        }

        $acumular = app(AcumularPuntos::class);
        EventoOutboxTenant::query()->whereIn('tipo', ['asistencia.marcada', 'orden.pagada', 'pago.anulado'])
            ->orderBy('id')
            ->chunkById(500, function ($eventos) use ($acumular): void {
                foreach ($eventos as $evento) {
                    Carbon::setTestNow($evento->ocurrido_en);
                    $acumular->handle(new EventoDeDominioTenant(
                        (string) $evento->ulid, $evento->tipo, $evento->agregado_tipo, $evento->agregado_id,
                        is_array($evento->payload) ? $evento->payload : [], $evento->correlation_id,
                    ));
                }
            });

        // Quienes más puntos juntaron canjean algo; casi todo ya se entregó.
        $puntos = app(PuntosTenant::class);
        $ids = $this->mezclar($this->idsDeClientes());
        $canjes = 0;
        foreach ($ids as $id) {
            if ($canjes >= 8) {
                break;
            }
            $saldo = $puntos->saldo($id);
            $alcanza = array_values(array_filter($premios, static fn (RecompensaLealtadTenant $r): bool => $r->costo_puntos <= $saldo));
            if ($alcanza === [] || ! $this->prob(60)) {
                continue;
            }
            $this->en($this->hoy->subDays($this->azar(0, 25))->setTime($this->azar(10, 19), $this->azar(0, 59)));
            $canje = $puntos->canjear($id, $this->uno($alcanza), $entrega);
            if ($this->prob(75)) {
                $puntos->entregarCanje($canje);
            }
            $canjes++;
            $this->sumar('canjes de lealtad');
        }
    }

    /**
     * Avisos que ya se mandaron a un segmento (por correo o en la cuenta) y las
     * automatizaciones que crean tareas al equipo.
     *
     * @param  list<array{0: int, 1: SegmentoComunicacion, 2: CanalComunicacion, 3: string, 4: string}>  $difusiones  [hace cuántos días, segmento, canal, asunto, mensaje]
     * @param  list<array{0: string, 1: EventoAutomatizacion, 2: string, 3: string|null, 4: int}>  $reglas  [nombre, evento, título de la tarea, detalle, minutos después]
     */
    protected function comunicaciones(array $difusiones, array $reglas): void
    {
        $difundir = app(DifundirComunicacionTenant::class);
        foreach ($difusiones as [$dias, $segmento, $canal, $asunto, $cuerpo]) {
            $enviada = $this->en($this->hoy->subDays($dias)->setTime(10, $this->azar(0, 40)));
            $difusion = $difundir->ejecutar($segmento, $canal, $asunto, $cuerpo);
            // Ya salió: el relay no la vuelve a mandar.
            foreach (MensajeTenant::query()->where('difusion_id', $difusion->getKey())->get() as $mensaje) {
                $entregado = $enviada->addMinutes($this->azar(1, 15));
                $mensaje->update([
                    'estado' => EstadoMensaje::Enviado->value, 'intentos' => 1,
                    'enviado_en' => $entregado->utc(),
                    'entregado_en' => $entregado->utc(),
                    'leido_en' => $this->prob(55) ? $entregado->addMinutes($this->azar(5, 600))->min($this->ahora)->utc() : null,
                ]);
            }
            $this->sumar('comunicaciones enviadas');
        }
        foreach ($reglas as [$nombre, $evento, $titulo, $detalle, $minutos]) {
            $this->en($this->inicio->setTime(9, 0));
            ReglaAutomatizacionTenant::query()->create([
                'nombre' => $nombre, 'evento' => $evento->value, 'condiciones' => null, 'accion' => AccionAutomatizacion::CrearTarea->value,
                'titulo_plantilla' => $titulo, 'detalle_plantilla' => $detalle, 'delay_minutos' => $minutos, 'activa' => true,
            ]);
            $this->sumar('automatizaciones');
        }
    }

    /**
     * Los datos fiscales del negocio y las facturas que pidieron algunos clientes de
     * sus compras recientes (timbradas con el proveedor de desarrollo).
     *
     * @param  array{0: string, 1: string, 2: string, 3: string}  $emisor  [razón social, RFC, régimen fiscal, código postal]
     */
    protected function facturas(array $emisor, int $cuantas, string $claveProdServ): void
    {
        [$razon, $rfc, $regimen, $cp] = $emisor;
        $datos = DatosFiscalesTenant::query()->create(['razon_social' => $razon, 'rfc' => $rfc, 'regimen_fiscal' => $regimen, 'codigo_postal' => $cp]);
        $ordenes = OrdenTenant::query()->with(['persona', 'lineas.producto', 'sesion.oferta'])
            ->where('estado', EstadoOrden::Pagada->value)
            ->whereNotNull('persona_id')
            ->where('pagada_en', '>=', $this->hoy->subDays(40)->utc())
            ->orderBy('id')
            ->get()->all();
        $emitir = app(EmitirFacturaTenant::class);
        foreach (array_slice($this->mezclar($ordenes), 0, $cuantas) as $orden) {
            $persona = $orden->persona;
            if (! $persona instanceof PersonaTenant) {
                continue;
            }
            // Lo que se compró (un plan) o el servicio de la cita. Lo cobrado ya trae IVA:
            // la factura lo separa.
            $conceptos = $orden->lineas->map(static fn ($l): array => [(string) $l->producto?->nombre, (int) $l->cantidad, (int) $l->precio_unitario_minor])->all();
            if ($conceptos === [] && $orden->sesion?->oferta !== null) {
                $conceptos = [[(string) $orden->sesion->oferta->nombre, 1, (int) $orden->total_minor]];
            }
            $items = [];
            foreach ($conceptos as [$descripcion, $cantidad, $precio]) {
                if ($descripcion === '' || $precio <= 0) {
                    continue;
                }
                $items[] = [
                    'descripcion' => $descripcion, 'cantidad' => max(1, $cantidad),
                    'precio_unitario_minor' => max(1, intdiv($precio * 100, 116)),
                    'clave_prod_serv' => $claveProdServ, 'clave_unidad' => 'E48',
                ];
            }
            if ($items === []) {
                continue;
            }
            $this->en(CarbonImmutable::instance($orden->pagada_en ?? $this->ahora)->addHours($this->azar(1, 48)));
            try {
                $emitir->emitir($datos, [
                    'nombre' => mb_strtoupper(Str::ascii($persona->nombreCompleto())), 'rfc' => $this->rfcDe($persona),
                    'email' => $persona->email, 'codigo_postal' => $this->uno(['06700', '03100', '06600', '04100', '11590', '03810']),
                    'regimen_fiscal' => '612',
                ], $items, 'G03', $this->uno(['01', '04', '03']));
                $this->sumar('facturas');
            } catch (Throwable) {
                // Sin timbre: no se cuenta.
            }
        }
    }

    /** Devuelve (todo o una parte) un pago en caja, con su motivo, al día siguiente. */
    protected function devolucion(?PagoTenant $pago, ?int $monto, string $motivo, Usuario $actor): void
    {
        if (! $pago instanceof PagoTenant) {
            return;
        }
        $this->en(CarbonImmutable::instance($pago->created_at ?? $this->ahora)->addDay()->setTimezone($this->zona));
        try {
            app(ReembolsarPagoTenant::class)->ejecutar($pago, $monto, $motivo, $actor);
            $this->sumar('devoluciones');
        } catch (Throwable) {
            // No se pudo devolver (ya devuelto, no aprobado o sus clases ya se usaron).
        }
    }

    /**
     * Una llave de API para un sistema externo (solo lectura). El secreto no se
     * guarda: solo su huella.
     *
     * @param  list<string>  $scopes
     */
    protected function llaveDeApi(string $nombre, array $scopes): void
    {
        $this->en($this->inicio->addDays(3)->setTime(16, 0));
        app(GestionarLlavesApiTenant::class)->crear($nombre, $scopes);
        $this->sumar('llaves de API');
    }

    /** Alguien que dejó de venir pidió que borren su cuenta y sus datos (ARCO). */
    protected function solicitudDePrivacidad(PersonaTenant $persona, string $motivo): void
    {
        $this->en($this->hoy->subDays(2)->setTime(21, 15));
        try {
            app(BajaDePersonaTenant::class)->solicitar($persona, $motivo);
            $this->sumar('solicitudes de privacidad');
        } catch (RuntimeException) {
            // Ya la había pedido.
        }
    }

    // ---------------------------------------------------------------- apoyo

    /**
     * Clientes (no el equipo) en orden fijo.
     *
     * @return list<int>
     */
    private function idsDeClientes(): array
    {
        /** @var list<int> $ids */
        $ids = PersonaTenant::query()->where('tipo', TipoPersonaTenant::Miembro->value)->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        return $ids;
    }

    /**
     * Las personas con cuenta primero (para revisar «Mi cuenta» con datos) y las demás
     * en otro orden.
     *
     * @param  list<PersonaTenant>  $personas
     * @return list<PersonaTenant>
     */
    private function conCuentaPrimero(array $personas): array
    {
        $conCuenta = array_values(array_filter($personas, static fn (PersonaTenant $p): bool => $p->usuario_id !== null));
        $resto = array_values(array_filter($personas, static fn (PersonaTenant $p): bool => $p->usuario_id === null));

        return [...$conCuenta, ...$this->mezclar($resto)];
    }

    /** Un momento entre unos días después de su alta, nunca después de ahora. */
    private function despuesDelAlta(PersonaTenant $persona, int $minDias, int $maxDias): CarbonImmutable
    {
        $alta = CarbonImmutable::instance($persona->created_at ?? $this->inicio)->setTimezone($this->zona);

        return $alta->addDays($this->azar($minDias, $maxDias))->addMinutes($this->azar(5, 240))->min($this->ahora->subMinutes(10));
    }

    /** RFC de persona física con el formato del SAT (de prueba). */
    private function rfcDe(PersonaTenant $persona): string
    {
        $letras = static fn (?string $texto, int $n): string => str_pad(substr((string) preg_replace('/[^A-Z]/', '', mb_strtoupper(NombresDemo::ascii((string) $texto))), 0, $n), $n, 'X');
        $nacio = $persona->fecha_nacimiento !== null ? CarbonImmutable::instance($persona->fecha_nacimiento) : $this->hoy->subYears($this->azar(20, 50));

        return $letras($persona->primer_apellido, 2).$letras($persona->segundo_apellido, 1).$letras($persona->nombre, 1)
            .$nacio->format('ymd').$this->uno(['A1B', 'K72', 'HN3', 'Q8T', 'M4R']);
    }

    /** Un PDF de una página con un título y un nombre (los documentos de muestra). */
    private function pdf(string $titulo, string $nombre): string
    {
        $texto = static fn (string $t): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], Str::ascii($t));
        $contenido = 'BT /F1 20 Tf 72 760 Td ('.$texto($titulo).') Tj ET BT /F1 12 Tf 72 730 Td ('.$texto($nombre).') Tj ET'
            .' BT /F1 10 Tf 72 700 Td (Documento de muestra del demo) Tj ET';
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($contenido)." >>\nstream\n{$contenido}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $posiciones = [];
        foreach ($objetos as $i => $objeto) {
            $posiciones[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$objeto}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach ($posiciones as $p) {
            $pdf .= sprintf("%010d 00000 n \n", $p);
        }

        return $pdf.'trailer << /Size '.(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
