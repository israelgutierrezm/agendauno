<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Parametros;

use App\Modules\Platform\Operacion\LimpiezaDeAltasSinActivar;
use App\Modules\Tenancy\Application\ActivacionPropietario;

/**
 * Todos los límites y datos de negocio que se pueden configurar (ADR 0042). Nada de
 * esto vive fijo en el código: el valor que aplica sale del negocio, si lo ajustó; si
 * no, de la plataforma; y si tampoco, del `defecto` de aquí.
 */
final class CatalogoParametros
{
    /**
     * @return array<string, DefinicionParametro>
     */
    public static function todos(): array
    {
        $e = DefinicionParametro::ENTERO;
        $sn = DefinicionParametro::SI_NO;
        $lista = [
            // Reservas y lista de espera.
            new DefinicionParametro('reservas.minutos_para_pagar', 'Reservas y lista de espera', 'Tiempo para pagar una reserva apartada',
                'Si no se paga en este tiempo, el lugar se libera.', $e, 30, 5, 1440, 'min'),
            // Cuándo reserva el cliente una clase desde su cuenta o la app (el negocio, siempre).
            new DefinicionParametro('reservas.dias_apertura', 'Reservas y lista de espera', 'El cliente reserva una clase desde',
                'Días antes de la clase. 0 = en cuanto se programa.', $e, 0, 0, 365, 'días', modalidad: 'clases'),
            new DefinicionParametro('reservas.minutos_cierre', 'Reservas y lista de espera', 'Las reservas de una clase cierran',
                'Minutos antes de que empiece. 0 = hasta que empieza.', $e, 0, 0, 1440, 'min', modalidad: 'clases'),
            new DefinicionParametro('reservas.minutos_para_aceptar_lugar', 'Reservas y lista de espera', 'Tiempo para aceptar un lugar de la lista de espera',
                'Si no lo acepta a tiempo, se ofrece al siguiente.', $e, 30, 5, 1440, 'min', modalidad: 'clases'),

            // Recordatorios.
            new DefinicionParametro('recordatorios.primero_horas', 'Recordatorios', 'Primer recordatorio',
                'Horas antes de la clase o cita.', $e, 24, 1, 168, 'h'),
            new DefinicionParametro('recordatorios.segundo_horas', 'Recordatorios', 'Segundo recordatorio',
                'Horas antes de la clase o cita. 0 = no se envía.', $e, 2, 0, 48, 'h'),

            // Reprogramar desde la cuenta del cliente (ADR 0044).
            new DefinicionParametro('reprogramar.horas_limite_cliente', 'Cambios de horario desde la cuenta', 'El cliente puede cambiar su horario hasta',
                'Horas antes del inicio. Después, solo el negocio.', $e, 12, 0, 720, 'h'),
            new DefinicionParametro('cancelacion.cliente_cancela_tarde', 'Cambios de horario desde la cuenta', 'El cliente puede cancelar después del límite sin costo',
                'Si lo apagas, pasado ese límite solo el negocio cancela (el cliente le escribe). Si lo dejas, él cancela y se aplica el cobro por cancelar tarde.', $sn, 1),
            new DefinicionParametro('reprogramar.maximo_cliente', 'Cambios de horario desde la cuenta', 'Cambios por reserva',
                'Cuántas veces puede cambiar él mismo el horario de una reserva. 0 = solo el negocio.', $e, 1, 0, 20),

            // Citas.
            new DefinicionParametro('citas.duracion_defecto', 'Citas', 'Duración de una cita si el servicio no la define',
                'Minutos.', $e, 30, 5, 480, 'min', modalidad: 'citas'),
            // ADR 0065.
            new DefinicionParametro('citas.pago_en_linea_obligatorio', 'Citas', 'Pedir el pago en línea para confirmar una cita',
                'Si lo apagas, la cita queda confirmada al agendar y el cliente paga en línea o en la sucursal. Sin cobro en línea activo, siempre se paga en la sucursal.', $sn, 1, modalidad: 'citas'),
            new DefinicionParametro('citas.minutos_anticipacion_minima', 'Citas', 'Anticipación mínima para agendar en línea',
                'Minutos antes de la cita. 0 = cualquier horario libre que aún no empiece. El negocio agenda sin este límite.', $e, 0, 0, 10080, 'min', modalidad: 'citas'),
            new DefinicionParametro('citas.dias_maximos_adelante', 'Citas', 'Se agenda en línea hasta',
                'Días adelante que el cliente ve y puede agendar.', $e, 60, 1, 365, 'días', modalidad: 'citas'),
            new DefinicionParametro('citas.agendar_sin_cuenta', 'Citas', 'Se puede agendar sin cuenta',
                'Si lo apagas, en tu página solo agendan clientes con cuenta (los que tú invitas).', $sn, 1, modalidad: 'citas'),
            new DefinicionParametro('citas.maximo_por_pagar', 'Citas', 'Citas por pagar que puede tener un cliente',
                'Con estas citas próximas sin pagar, el cliente ya no puede agendar otra en línea ni desde su cuenta (el negocio sí puede agendarle). 0 = sin límite.', $e, 5, 0, 50, 'citas', modalidad: 'citas'),

            // Clases recurrentes (ADR 0045).
            new DefinicionParametro('agenda.dias_a_generar', 'Clases recurrentes', 'Fechas creadas por adelantado',
                'Días hacia adelante con fechas de las clases recurrentes listas para reservar.', $e, 30, 7, 365, 'días', modalidad: 'clases'),

            // Lugares apartados para un canal (Wellhub, TotalPass, ClassPass…): sin
            // devolverlos antes de la clase, los clientes directos la verían llena.
            new DefinicionParametro('canales.liberar_horas_antes', 'Lugares por canal', 'Los lugares apartados se liberan',
                'Horas antes de la clase en que los lugares apartados para un canal que no se usaron vuelven a estar disponibles para todos. Es el valor que se propone al apartar lugares.', $e, 2, 0, 168, 'h', modalidad: 'clases'),

            // Wellhub y TotalPass: reservas desde su app (ADR 0149).
            new DefinicionParametro('plataformas.dias_publicados', 'Wellhub y TotalPass', 'Clases publicadas por adelantado',
                'Días hacia adelante con las clases que se pueden reservar desde la app de Wellhub o TotalPass.', $e, 14, 1, 60, 'días', modalidad: 'clases'),
            new DefinicionParametro('plataformas.lugares_sin_tope', 'Wellhub y TotalPass', 'Lugares en clases sin cupo',
                'Cuántos lugares se ofrecen en la app de Wellhub o TotalPass cuando la clase no tiene cupo.', $e, 20, 1, 500, 'lugares', modalidad: 'clases'),

            // Tope de mensajes de WhatsApp del negocio (ADR 0186).
            new DefinicionParametro('whatsapp.tope_mensual', 'WhatsApp', 'Máximo de mensajes de WhatsApp al mes',
                'Al llegar, los avisos por WhatsApp de ese mes ya no salen (sí por correo y en la app). 0 = sin tope. Solo cuenta si la plataforma cobra los mensajes.', $e, 0, 0, 100000, 'mensajes'),

            // Asistencia (ADR 0101).
            new DefinicionParametro('asistencia.minutos_antes', 'Asistencia', 'Se puede pasar lista desde',
                'Minutos antes de que empiece la clase o cita. Antes no se registra la asistencia.', $e, 30, 0, 240, 'min'),
            new DefinicionParametro('asistencia.no_asistio_al_terminar', 'Asistencia', 'Al terminar, quien no tiene registro «no se presentó»',
                'Si nadie registró la asistencia de alguien, al terminar la clase o cita queda como que no se presentó, con la política de inasistencias del negocio.', $sn, 1),

            // Familias y responsables (ADR 0123).
            new DefinicionParametro('familias.max_participantes', 'Familias', 'Participantes por responsable',
                'Cuántas personas puede tener a su cargo un responsable (con la autorización pendiente o vigente).', $e, 6, 1, 20, 'personas'),

            // Sitio del negocio (ADR 0114).
            new DefinicionParametro('sitio.banners_maximos', 'Sitio del negocio', 'Banners en el sitio',
                'Cuántos banners (promociones, avisos) puede tener la página del negocio.', $e, 5, 1, 20, 'banners'),
            new DefinicionParametro('sitio.imagenes_maximas', 'Sitio del negocio', 'Imágenes del sitio',
                'Cuántas fotos propias puede tener la página del negocio (las de «Nosotros», los banners, las galerías y los bloques).', $e, 40, 5, 200, 'imágenes'),
            new DefinicionParametro('sitio.bloques_maximos', 'Sitio del negocio', 'Bloques propios por página',
                'Cuántos bloques propios (texto con imagen, galería, preguntas, testimonios, video…) puede tener cada página del sitio (ADR 0146).', $e, 20, 1, 60, 'bloques'),
            new DefinicionParametro('sitio.paginas_maximas', 'Sitio del negocio', 'Páginas del sitio',
                'Cuántas páginas propias puede tener el sitio, además de Inicio (ADR 0146).', $e, 8, 1, 30, 'páginas'),

            // Acceso.
            new DefinicionParametro('acceso.minutos_antes', 'Acceso', 'Se puede entrar desde',
                'Minutos antes de que empiece su clase o cita.', $e, 30, 0, 240, 'min', modalidad: 'clases'),

            // Membresías (ADR 0047).
            new DefinicionParametro('membresias.dias_aviso_renovacion', 'Membresías', 'Avisar la renovación',
                'Días antes de que se renueve una membresía.', $e, 3, 1, 30, 'días', modalidad: 'clases'),
            new DefinicionParametro('membresias.dias_por_vencer', 'Membresías', 'Una membresía está por vencer desde',
                'Días antes de su vencimiento. Aplica en la ficha del alumno, el radar de retención y las difusiones.', $e, 14, 1, 90, 'días'),
            new DefinicionParametro('membresias.dias_vencida_recuperable', 'Membresías', 'Una membresía vencida se sigue buscando durante',
                'Días después de vencer, en el radar de retención y las difusiones.', $e, 14, 1, 365, 'días'),

            // Seguimiento (plan de mejoras M09/C06/C05): tareas para el equipo por señales
            // que el sistema verifica, sin repetirlas.
            new DefinicionParametro('seguimiento.activo', 'Seguimiento', 'Crear tareas de seguimiento',
                'Cada día: su primera clase, plan por vencer o vencido, días sin asistir y quien compró y no ha reservado. Si lo apagas, no se crean.', $sn, 1),
            new DefinicionParametro('seguimiento.dias_sin_asistir', 'Seguimiento', 'Seguimiento por inasistencia a los',
                'Días desde su última clase, con un plan en vigor.', $e, 21, 7, 180, 'días'),
            new DefinicionParametro('seguimiento.dias_sin_reservar', 'Seguimiento', 'Seguimiento a quien compró y no ha reservado a los',
                'Días desde que pagó su plan sin reservar ninguna clase.', $e, 3, 1, 30, 'días'),
            // Retención por segmentos (ADR 0159): quien vino y no ha comprado nada.
            new DefinicionParametro('seguimiento.dias_vino_sin_comprar', 'Seguimiento', 'Buscar a quien vino y no ha comprado durante',
                'Días desde su última clase, sin ningún plan comprado.', $e, 30, 7, 180, 'días', modalidad: 'clases'),
            new DefinicionParametro('seguimiento.dias_entre_contactos', 'Seguimiento', 'No repetir un seguimiento por el mismo motivo antes de',
                'Días desde el anterior con esa persona.', $e, 30, 1, 365, 'días'),
            new DefinicionParametro('membresias.max_dias_pausa', 'Membresías', 'Una pausa puede durar hasta',
                'Días.', $e, 180, 1, 365, 'días'),

            // Cobranza.
            new DefinicionParametro('cobranza.dias_gracia_pago_automatico', 'Cobranza', 'Espera antes de dar por fallido un pago automático',
                'Días después de la fecha de cobro sin recibirlo.', $e, 3, 0, 30, 'días', modalidad: 'clases'),
            new DefinicionParametro('cobranza.dias_gracia_adeudo', 'Cobranza', 'Días de gracia con un adeudo',
                'Antes de suspender la membresía por falta de pago.', $e, 7, 0, 60, 'días'),
            new DefinicionParametro('cobranza.dias_pagar_en_tienda', 'Cobranza', 'Días para pagar en tienda (OXXO)',
                'Vigencia de la referencia de pago en efectivo de Mercado Pago u OpenPay.', $e, 3, 1, 30, 'días'),
            // Reintentos de un adeudo en mora (cobranza): días desde el fallo.
            new DefinicionParametro('cobranza.reintento_1_dias', 'Cobranza', 'Primer reintento de un pago en mora',
                'Días después del fallo.', $e, 1, 1, 30, 'días'),
            new DefinicionParametro('cobranza.reintento_2_dias', 'Cobranza', 'Segundo reintento de un pago en mora',
                'Días después del fallo.', $e, 3, 1, 60, 'días'),
            new DefinicionParametro('cobranza.reintento_3_dias', 'Cobranza', 'Tercer reintento y los siguientes',
                'Días después del fallo.', $e, 7, 1, 90, 'días'),

            // Facturación (ADR 0047).
            new DefinicionParametro('facturacion.iva_porcentaje', 'Facturación', 'Tasa de IVA de las facturas',
                '8 % solo si el negocio aplica el estímulo de la región fronteriza.', $e, 16, 8, 16, '%', opciones: [16, 8]),
            new DefinicionParametro('facturacion.automatica_citas', 'Facturación', 'Facturar las citas en automático',
                'A los clientes que guardaron sus datos fiscales y pidieron factura automática: el anticipo al pagarlo y la cita al pagar el resto, con el CFDI de egreso que aplica el anticipo. Solo negocios de citas.', $sn, 0),

            // Devoluciones (ADR 0046).
            // Cobros en caja (ADR 0086): corregir la forma de pago de un cobro.
            // Precio por forma de pago (ADR 0191).
            new DefinicionParametro('ventas.descuento_contado_pct', 'Cobros en caja', 'Descuento por pagar de contado',
                'Porcentaje que se descuenta al cobrar en caja todo en efectivo o todo por transferencia. 0 = el mismo precio con cualquier forma de pago. No aplica a pagos en línea ni con varias formas.', $e, 0, 0, 30, '%'),
            new DefinicionParametro('pagos.permitir_corregir_metodo', 'Cobros en caja', 'Permitir corregir la forma de pago de un cobro',
                'Si se registró en efectivo y era transferencia (o al revés), se corrige sin cambiar el monto. Queda en la bitácora. No aplica a pagos en línea ni a ventas ya facturadas.', $sn, 1),
            new DefinicionParametro('pagos.horas_para_corregir', 'Cobros en caja', 'La forma de pago se puede corregir hasta',
                'Horas después del cobro. 0 = sin límite.', $e, 48, 0, 720, 'h'),
            // ADR 0087: anular un cobro en caja registrado por error.
            new DefinicionParametro('pagos.permitir_anular_cobro', 'Cobros en caja', 'Permitir anular un cobro registrado por error',
                'El cobro queda anulado y la venta vuelve a quedar por cobrar; si dio créditos o una membresía sin usar, se retiran. Queda en la bitácora. No aplica a pagos en línea, renovaciones, ventas facturadas ni créditos ya usados.', $sn, 0),
            new DefinicionParametro('pagos.horas_para_anular', 'Cobros en caja', 'Un cobro se puede anular hasta',
                'Horas después del cobro. 0 = sin límite.', $e, 24, 0, 720, 'h'),

            // ADR 0178: devolver algunos productos de una venta del mostrador.
            new DefinicionParametro('pos.dias_para_devolver', 'Devoluciones', 'Un producto vendido en el mostrador se puede devolver hasta',
                'Días después de la venta. El dinero sale de la caja abierta y las piezas vuelven al inventario. 0 = sin límite.', $e, 30, 0, 365, 'días'),
            new DefinicionParametro('cancelacion.devolver_pago_si_cancela_negocio', 'Devoluciones', 'Si el negocio cancela algo ya pagado en línea, devolver el pago',
                'Se devuelve solo, por la misma pasarela. Lo pagado en efectivo se devuelve en caja.', $sn, 0),

            // Reseñas.
            new DefinicionParametro('resenas.dias_para_calificar', 'Reseñas', 'Días para calificar una clase o cita',
                'Después ya no se pide la reseña.', $e, 30, 1, 365, 'días'),
            new DefinicionParametro('resenas.publicar_sin_revisar', 'Reseñas', 'Publicar las reseñas sin revisarlas',
                'Si lo apagas, cada reseña nueva queda oculta en tu página hasta que la apruebes en Reseñas.', $sn, 1),

            // Captación (plan de mejoras B01, ADR 0117).
            new DefinicionParametro('captacion.activa', 'Captación', 'Recibir prospectos',
                'Quien todavía no es cliente puede dejar sus datos en tus formularios (tu página, un enlace, un QR o tu propio sitio) y llega a Prospectos. Apagado, «Pedir acceso» te contacta por WhatsApp o teléfono.', $sn, 1),
            // ADR 0138.
            new DefinicionParametro('captacion.horas_para_atender', 'Captación', 'Horas para atender un prospecto',
                'Si en este tiempo nadie le escribe ni le da seguimiento, se marca «sin atender», su responsable recibe una tarea y se avisa al equipo para reasignarlo. 0 = no se revisa.', $e, 24, 0, 168, 'h'),
            // Atribución de campañas (ADR 0142). En la plataforma, la del registro de negocios.
            new DefinicionParametro('medicion.dias_ultimo_toque', 'Captación', 'Una campaña cuenta durante',
                'Días desde que alguien llega por una campaña (un enlace con UTM, un anuncio u otro sitio) en que su solicitud, su compra o su cita se le atribuyen. Pasado ese tiempo sin volver por otra, queda «sin atribución».', $e, 30, 1, 90, 'días'),
            new DefinicionParametro('medicion.dias_primer_toque', 'Captación', 'El primer origen se recuerda durante',
                'Días en que se guarda por dónde llegó alguien la primera vez, aunque después vuelva por otra campaña.', $e, 90, 1, 365, 'días'),

            // Inventario del mostrador.
            new DefinicionParametro('inventario.stock_bajo', 'Inventario', 'Stock bajo',
                'Con esta cantidad o menos en una sucursal, el producto se marca con stock bajo.', $e, 3, 0, 1000, 'piezas'),

            // Cancelaciones: el negocio las ajusta en Reglas de la agenda; esto es lo que
            // aplica a los negocios que aún no definen su política.
            new DefinicionParametro('cancelacion.horas_limite', 'Cancelaciones (negocios sin política propia)', 'Cancelar sin costo hasta',
                'Horas antes del inicio.', $e, 6, 0, 720, 'h', porNegocio: false),
            new DefinicionParametro('cancelacion.penaliza_tarde', 'Cancelaciones (negocios sin política propia)', 'Cobrar el crédito si cancela tarde',
                '', $sn, 1, porNegocio: false),
            new DefinicionParametro('cancelacion.penaliza_no_show', 'Cancelaciones (negocios sin política propia)', 'Cobrar el crédito si no asiste',
                '', $sn, 1, porNegocio: false),
            new DefinicionParametro('cancelacion.tolerancia_no_show', 'Cancelaciones (negocios sin política propia)', 'Inasistencias toleradas sin cobrar',
                'Cuántas faltas no cobran el crédito antes de empezar a cobrarlo. 0 = ninguna.', $e, 0, 0, 100, '', porNegocio: false),
            new DefinicionParametro('cancelacion.ventana_no_show_dias', 'Cancelaciones (negocios sin política propia)', 'Se cuentan las inasistencias de los últimos',
                'Días.', $e, 30, 1, 365, 'días', porNegocio: false),

            // Solo la plataforma (ADR 0047). El pase se renueva cada minuto en la
            // pantalla del alumno: menos de 90 s lo dejaría vencer antes.
            // Pase en Apple Wallet y Google Wallet (ADR 0195): su código no caduca.
            new DefinicionParametro('acceso.pase_en_wallet', 'Acceso', 'Pase de entrada en la cartera del teléfono',
                'El cliente agrega su pase a Apple Wallet o Google Wallet. Su código no cambia cada pocos minutos como el de la app: si lo apagas, esos pases dejan de servir y se usa el QR de la app.', $sn, 1),
            new DefinicionParametro('acceso.segundos_pase_qr', 'Acceso', 'Vigencia del pase QR de entrada',
                'Segundos. Una captura de pantalla deja de servir después.', $e, 180, 90, 900, 's', porNegocio: false),
            new DefinicionParametro('importaciones.max_filas', 'Importaciones', 'Filas por archivo al importar miembros o personal',
                '', $e, 1000, 100, 10000, 'filas', porNegocio: false),
            new DefinicionParametro('importaciones.max_sesiones_agenda', 'Importaciones', 'Sesiones por carga de agenda',
                'Incluye las fechas que se expanden desde las filas semanales. Divide archivos mayores en varios lotes.', $e, 500, 10, 2000, 'sesiones', porNegocio: false),

            // Cuentas: vigencia de enlaces de seguridad (solo la plataforma).
            new DefinicionParametro('cuentas.horas_confirmar_correo', 'Cuentas', 'Vigencia del enlace para confirmar un cambio de correo',
                'Horas.', $e, 24, 1, 168, 'h', porNegocio: false),
            new DefinicionParametro('cuentas.minutos_restablecer_contrasena', 'Cuentas', 'Vigencia del enlace para restablecer la contraseña',
                'Minutos.', $e, 60, 10, 1440, 'min', porNegocio: false),
            // ADR 0169: vale para el dueño que registra su negocio y para quien el
            // negocio invita (equipo o clientes).
            new DefinicionParametro(ActivacionPropietario::CLAVE_DIAS, 'Cuentas', 'Vigencia del enlace para activar una cuenta',
                'Días. Si vence, la persona pide otro desde la pantalla de activación o el negocio la vuelve a invitar.', $e, 7, 1, 30, 'días', porNegocio: false),

            // Registro público: las altas que nadie activa se borran (ADR 0102, solo la
            // plataforma). Al menos 3 días: el correo de activación puede leerse tarde.
            new DefinicionParametro(LimpiezaDeAltasSinActivar::CLAVE_DIAS, 'Cuentas', 'Días para activar un negocio recién registrado',
                'Si el dueño no activa su cuenta en este plazo y el negocio no tiene nada, se borra y su nombre queda libre.', $e, 14, 3, 365, 'días', porNegocio: false),

            // Suspensión automática por renta vencida (ADR 0073, solo la plataforma).
            new DefinicionParametro('renta.dias_gracia_suspension', 'Renta', 'Días de gracia antes de suspender por renta vencida',
                'Pasados estos días desde el vencimiento, el negocio se suspende solo hasta que la pague. 0 = nunca.', $e, 15, 0, 90, 'días', porNegocio: false),
            new DefinicionParametro('renta.dias_aviso_suspension', 'Renta', 'Días antes de la suspensión para avisar al dueño',
                'Por correo y, si lo aceptó, por WhatsApp.', $e, 3, 1, 14, 'días', porNegocio: false),
            // El aviso del pago en el panel (ADR 0152).
            new DefinicionParametro('renta.dias_recordatorio_pago', 'Renta', 'Días antes del vencimiento para recordar el pago en el panel',
                'Solo si no hay tarjeta que lo pague sola. 0 = solo se avisa ya vencido.', $e, 3, 0, 14, 'días', porNegocio: false),
            // Renta en dólares cobrada en pesos (ADR 0107, solo la plataforma).
            new DefinicionParametro('renta.dias_para_pagar', 'Renta', 'Días para pagar cada cargo de la suscripción',
                'Desde que se emite cada cargo (lo que se cobra mes vencido, nunca antes del fin del mes) hasta que vence.', $e, 10, 1, 60, 'días', porNegocio: false),
            // Stripe no cobra menos de 10 pesos ni de 50 centavos de dólar: lo que no llega
            // al mínimo no se cobra (el periodo queda cubierto, sin cargo).
            new DefinicionParametro('renta.cargo_minimo_mxn_centavos', 'Renta', 'Cargo mínimo en pesos',
                'Total con IVA, en centavos. Un periodo (o la diferencia de un cambio de plan) que cueste menos no se cobra.', $e, 1000, 1, 100000, '¢', porNegocio: false),
            new DefinicionParametro('renta.cargo_minimo_usd_centavos', 'Renta', 'Cargo mínimo en dólares',
                'Total con IVA, en centavos de dólar. Un periodo (o la diferencia de un cambio de plan) que cueste menos no se cobra.', $e, 50, 1, 10000, '¢', porNegocio: false),
            new DefinicionParametro('renta.reintento_1_dias', 'Renta', 'Primer reintento del cobro automático',
                'Días después de emitido el cargo, si la tarjeta lo rechazó. 0 = no se reintenta.', $e, 3, 0, 30, 'días', porNegocio: false),
            new DefinicionParametro('renta.reintento_2_dias', 'Renta', 'Segundo reintento del cobro automático',
                'Días después de emitido el cargo. 0 = no hay segundo reintento.', $e, 7, 0, 60, 'días', porNegocio: false),
            // Servicios con precio publicado (ADR 0188).
            new DefinicionParametro('servicios.arranque_centavos', 'Renta', 'Arranque acompañado',
                'Pago único, en centavos de peso sin IVA: clases, paquetes, horario, pasarela y sitio con plantilla. Gratis con el plan anual.', $e, 390000, 0, 10000000, '¢', porNegocio: false),
            new DefinicionParametro('servicios.arranque_migracion_centavos', 'Renta', 'Arranque con migración',
                'Pago único, en centavos de peso sin IVA: el arranque y la migración de clientes con sus saldos y membresías.', $e, 490000, 0, 10000000, '¢', porNegocio: false),
            new DefinicionParametro('servicios.app_marca_alta_centavos', 'Renta', 'App con la marca del negocio: alta',
                'Pago único, en centavos de peso sin IVA.', $e, 490000, 0, 10000000, '¢', porNegocio: false),
            new DefinicionParametro('servicios.app_marca_mensual_centavos', 'Renta', 'App con la marca del negocio: mensualidad',
                'Cada mes con el complemento encendido, en centavos de peso sin IVA; se cobra aparte de la renta.', $e, 99000, 0, 10000000, '¢', porNegocio: false),
            // WhatsApp por mensaje (ADR 0186).
            new DefinicionParametro('whatsapp.cobro_por_mensaje', 'Renta', 'Los negocios pagan sus mensajes de WhatsApp',
                'Encendido, cada aviso por WhatsApp gasta un mensaje del saldo que el negocio compra en paquetes. Apagado, la plataforma los paga.', $sn, 0, porNegocio: false),
            new DefinicionParametro('whatsapp.precio_mensaje_centavos', 'Renta', 'Precio de cada mensaje de WhatsApp',
                'En centavos de peso, sin IVA: la tarifa de Meta por mensaje de utilidad más el margen de la plataforma.', $e, 45, 1, 10000, '¢', porNegocio: false),
            // Las plantillas de marketing de cada negocio cuestan más en Meta (ADR 0190).
            new DefinicionParametro('whatsapp.mensajes_por_marketing', 'Renta', 'Mensajes del saldo que gasta uno de marketing',
                'Meta cobra los de marketing varias veces más caros que los de utilidad: cada uno gasta estos mensajes del saldo del negocio.', $e, 5, 1, 20, 'mensajes', porNegocio: false),
            new DefinicionParametro('timbres.precio_centavos', 'Renta', 'Precio de cada timbre para facturar',
                'En centavos de peso, sin IVA. Los paquetes que se venden se fijan en Configuración → Datos comerciales.', $e, 180, 1, 10000, '¢', porNegocio: false),
            new DefinicionParametro('renta.tipo_cambio_dias_vigencia', 'Renta', 'Antigüedad máxima del tipo de cambio',
                'Para cobrar en pesos la renta en dólares. Uno más viejo ya no se usa: el cargo espera y se te avisa.', $e, 7, 1, 60, 'días', porNegocio: false),

            // El asistente sobre los datos del negocio (ADR 0196).
            new DefinicionParametro('asistente.activo', 'Asistente', 'Asistente con IA para los negocios',
                'Encendido (y con la llave de la API), los negocios con reportes avanzados le preguntan a un asistente por sus cifras. Cada pregunta cuesta.', $sn, 0, porNegocio: false),
            new DefinicionParametro('asistente.preguntas_por_dia', 'Asistente', 'Preguntas al asistente por día',
                'Por negocio. 0 = sin asistente.', $e, 40, 0, 500, 'preguntas'),

            // La demo de 15 minutos que se agenda desde la landing (ADR 0193).
            new DefinicionParametro('demos.activas', 'Demos', 'Agendar demos desde la landing',
                'Encendido, la landing ofrece agendar una demo en los horarios de abajo (hora del centro de México).', $sn, 0, porNegocio: false),
            new DefinicionParametro('demos.minutos', 'Demos', 'Duración de cada demo',
                'También es cada cuánto empieza una.', $e, 15, 10, 60, 'min', porNegocio: false),
            new DefinicionParametro('demos.hora_inicio', 'Demos', 'Primera hora para una demo',
                'De lunes a viernes (y sábado, si se enciende abajo).', $e, 10, 0, 23, 'h', porNegocio: false),
            new DefinicionParametro('demos.hora_fin', 'Demos', 'Las demos terminan a más tardar a las',
                'La última empieza para terminar a esta hora.', $e, 18, 1, 24, 'h', porNegocio: false),
            new DefinicionParametro('demos.sabados', 'Demos', 'También los sábados',
                'Con el mismo horario.', $sn, 0, porNegocio: false),
            new DefinicionParametro('demos.dias_adelante', 'Demos', 'Días hacia adelante que se ofrecen',
                'Cuántos días se pueden elegir, desde hoy.', $e, 10, 1, 30, 'días', porNegocio: false),
            new DefinicionParametro('demos.horas_anticipacion', 'Demos', 'Anticipación mínima',
                'Horas antes de que empiece, para que alguien la tome.', $e, 3, 0, 72, 'h', porNegocio: false),
            new DefinicionParametro('demos.por_horario', 'Demos', 'Demos a la misma hora',
                'Cuántas personas atienden demos: ese número de demos cabe en cada horario.', $e, 1, 1, 10, 'demos', porNegocio: false),

            // Avisos a los dueños (ADR 0071, solo la plataforma).
            new DefinicionParametro('duenos.dias_aviso_prueba', 'Dueños', 'Días antes del fin de la prueba para avisar al dueño',
                'Por correo y, si lo aceptó, por WhatsApp.', $e, 3, 1, 14, 'días', porNegocio: false),

            // WhatsApp con los dueños (ADR 0070): cada código cuesta (solo la plataforma).
            new DefinicionParametro('whatsapp.codigos_por_numero_hora', 'WhatsApp', 'Códigos de verificación por número en una hora',
                'Al registrarse. Pasado el tope, el dueño sigue sin verificar.', $e, 3, 1, 10, 'códigos', porNegocio: false),
            new DefinicionParametro('whatsapp.codigos_por_dia', 'WhatsApp', 'Códigos de verificación al día (toda la plataforma)',
                'Protege el costo si alguien abusa del registro; al llegar, avisa al superadministrador.', $e, 300, 10, 100000, 'códigos', porNegocio: false),
            // Archivos de la bandeja (ADR 0200): lo que se guarda de cada uno (solo la plataforma).
            new DefinicionParametro('bandeja.archivo_max_mb', 'WhatsApp', 'Tamaño máximo de un archivo de la bandeja',
                'Lo que llega más grande no se guarda (se avisa que se abra en el teléfono) y no se deja mandar.', $e, 16, 1, 100, 'MB', porNegocio: false),
            // Respuesta automática a quien contesta al número de AgendaUno (ADR 0083).
            new DefinicionParametro('whatsapp.horas_entre_respuestas', 'WhatsApp', 'Horas antes de volver a contestar a la misma persona',
                'A quien le escribe al número de AgendaUno se le contesta solo, una vez en este plazo aunque escriba varias veces. BAJA siempre se contesta.', $e, 12, 1, 168, 'h', porNegocio: false),

            // Una sesión que no se usa vence (en el negocio, puede ser menos).
            new DefinicionParametro('sesion.dias_inactividad', 'Sesiones', 'Días sin usarse para que una sesión venza',
                'Quien no entra en ese plazo vuelve a poner su contraseña. Protege si alguien deja la sesión abierta en una computadora prestada.', $e, 60, 1, 365, 'días'),

            // Limpieza de registros técnicos (ADR 0079, solo la plataforma).
            new DefinicionParametro('limpieza.dias_envios_whatsapp', 'Limpieza de registros', 'Días que se guarda el registro de cada WhatsApp enviado',
                'Sirve para saber si se entregó o se leyó. Después, lo que Meta avise de ese mensaje se ignora.', $e, 30, 7, 365, 'días', porNegocio: false),
            new DefinicionParametro('limpieza.dias_verificaciones_whatsapp', 'Limpieza de registros', 'Días que se guardan los códigos de verificación de WhatsApp',
                'Solo se guarda su huella, nunca el código. Cuentan para los topes de códigos por hora y por día.', $e, 7, 2, 90, 'días', porNegocio: false),
            new DefinicionParametro('limpieza.dias_sesiones_tarjeta', 'Limpieza de registros', 'Días que se guardan las sesiones para autorizar tarjetas',
                'Sirven para registrar la tarjeta del pago automático si el aviso de Stripe no llega; se revisan hasta 48 horas.', $e, 30, 3, 365, 'días', porNegocio: false),
            new DefinicionParametro('limpieza.dias_errores', 'Limpieza de registros', 'Días que se guarda un error que dejó de pasar',
                'Contados desde la última vez que pasó, esté abierto, resuelto o ignorado.', $e, 90, 7, 730, 'días', porNegocio: false),
            // ADR 0173: los pagos y su bitácora se quedan; esto es la copia del aviso.
            new DefinicionParametro('limpieza.dias_avisos_pasarela', 'Limpieza de registros', 'Días que se guarda cada aviso de una pasarela de cobro ya procesado',
                'La copia del aviso de Stripe, Mercado Pago u OpenPay, para revisar qué llegó. Los pendientes no se borran.', $e, 30, 7, 365, 'días', porNegocio: false),

            // Registro de negocios por producto (ADR 0108, solo la plataforma). TurnoUno
            // se lanza después: mientras esté cerrado, su landing junta interesados.
            new DefinicionParametro('registro.abierto_agendauno', 'Registro de negocios', 'Registro abierto en AgendaUno',
                'Negocios de clases. Si lo cierras, el registro avisa que por ahora no se reciben altas.', $sn, 1, porNegocio: false),
            new DefinicionParametro('registro.abierto_turnouno', 'Registro de negocios', 'Registro abierto en TurnoUno',
                'Negocios de citas. Cerrado, la landing de TurnoUno junta interesados en lugar de registrar negocios.', $sn, 1, porNegocio: false),

            // Monitoreo de errores (ADR 0080, solo la plataforma).
            new DefinicionParametro('errores.nuevos_clientes_por_dia', 'Monitoreo de errores', 'Errores nuevos de la web y la app al día',
                'Protege el monitoreo si alguien manda errores falsos. Los errores ya conocidos se siguen contando; al llegar al tope, avisa al superadministrador.', $e, 200, 10, 10000, 'errores', porNegocio: false),
        ];

        $porClave = [];
        foreach ($lista as $definicion) {
            $porClave[$definicion->clave] = $definicion;
        }

        return $porClave;
    }

    public static function de(string $clave): DefinicionParametro
    {
        return self::todos()[$clave] ?? throw new \InvalidArgumentException("Parámetro desconocido: {$clave}");
    }
}
