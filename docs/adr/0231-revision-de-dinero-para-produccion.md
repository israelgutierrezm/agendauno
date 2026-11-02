# ADR 0231 — Revisión de dinero para producción: efectos automáticos, ciclos, cupones, caja y renta

Estado: Aceptado (2026-10-31). Corrige hallazgos de la revisión para producción sobre las ADR
0024 (renovación en caja), 0087 (lo que se retira al devolver), 0161/0181 (anticipos), 0168
(contracargos), 0175 (caja), 0188 (referidos), 0218 (prepagados), 0219 (comisiones) y 0220 (cupones).

## Contexto

El núcleo de cobro, confirmación y devolución en línea estaba sólido. Los problemas estaban en lo que
ocurre sin una persona enfrente: una inasistencia automática que devolvía dinero, ciclos que vencían
créditos ya apartados, moras que no entraban en negocios solo en caja, y devoluciones que fallaban en
silencio. También en los bordes de cupones, presupuestos y corrección de cobros de turnos cerrados.

## Decisiones del dueño

- **La inasistencia automática no devuelve dinero (P1, ya en `d3662809`).** Abre `revisar_si_vino` en
  Por conciliar con el monto. Una marca a mano de «no llegó» devuelve y la cierra; «sí llegó», la cierra.
- **Créditos apartados (P4).** `expira = max(0, saldo - acarreo - retenciones activas)`, de la clase que
  sea: al reservar se exige el disponible de hoy, sin importar en qué ciclo cae.
- **Registrar pago y condonar (P6).** «Registrar pago» cobra de verdad: `POST /acuerdos/{a}/deuda` da la
  renovación por cobrar y se liquida en caja (entra al corte, avanza la fecha, cierra la mora). Quitamos
  `POST /acuerdos/{a}/regularizar`, que reactivaba sin cobrar. Condonar es otra acción:
  `POST /acuerdos/{a}/condonar`, con motivo, permiso `cobranza.condonar`, bitácora
  (`membresia.adeudo_condonado`) y anulación de la renovación; la fecha avanza.
- **Comisión en % (P7).** Base: pagos aprobados menos devoluciones aprobadas, solo con asistencia
  `presente`; de una inasistencia, lo conservado es del negocio. Un prepagado cuenta lo neto de su concepto
  entre las veces compradas. Un bono vale lo que valía al reservar (`reservas.valor_servicio_minor`; las
  reservas anteriores usan el precio de hoy). La propina va a `PersonalDeSesionTenant::imparte`.
- **Solo en caja (P8).** El día de la renovación con la orden pendiente entra a mora con
  `MotivoFalloCobro::SinPagoEnCaja`; `escalar-dunning` suspende al vencer la gracia.
- **Cobro de Mercado Pago tras un pago a mano (P15).** Sin renovación pendiente y con la siguiente fecha
  más allá de `cobranza.dias_gracia_pago_automatico`: se registra en la última renovación pagada, con
  incidencia `pago_duplicado` y `excedente_minor`. No avanza otro periodo.
- **Cupones (P17, P26m).** Un descuento ya prometido se respeta aunque el cupón se agote: apartan sin
  caducidad las órdenes de una reserva viva. `usos` puede rebasar el tope; solo no se dan descuentos
  nuevos. Tope opcional `promociones.usos_por_persona`.
- **Mover dentro del límite (P21).** La marca `reservas.cambio_tardio`: si después se cancela, es tardía.
  Cancelar tarde una cita prepagada gasta la vez (`motivo_cancelacion = tardia`).
- **Corregir un turno cerrado (P22).** Forma de pago y anulación piden `caja.supervisar` y sus renglones
  llevan `requiere_revision`, visible en el libro de la caja.
- **Puntos (P26p).** Una devolución total los retira. `n` (el reporte de corte) no se toca.
- **Renta (P26v, P26w).** Los días suspendidos completos no se cobran (`suspensiones_estudio`, lo abre y
  cierra el cambio de estado de `Estudio`; desde este cambio, sin datos previos). Meses gratis por invitar:
  tope `referidos.meses_gratis_por_anio` (superadmin, 12) y al darse de baja el invitado se retiran los no aplicados.
- **Portal (compras huérfanas).** Comprar lleva directo a pagar; repetir reutiliza la orden abierta del
  mismo producto; `DELETE /mi/ordenes/{orden}` la quita; caduca a `cobranza.dias_compra_sin_pagar` días
  (`ordenes.caduca_en`, solo compras del portal: nunca renovaciones, citas ni ventas del equipo).

## Lo que se corrigió sin necesidad de decisión

- **P3.** La misma llave: un pago abierto de hace menos de 15 minutos repite su respuesta (se guarda cifrada
  en `pagos.checkout_guardado`); uno rechazado, anulado o abandonado libera la llave y abre otro intento.
- **P5.** Una línea con servicio y citas vivas, atendidas o canceladas tarde cuenta como usada; `citaVigente`
  incluye `linea_orden_id`. Una parcial quita las veces que cubre (`lineas_orden.veces_devueltas`) o se
  rechaza si no quedan libres. Hecha en el panel de la pasarela: queda solo el dinero y una incidencia.
- **P9.** En caja, si la pasarela no responde al cancelar el intento abierto, se cierra sin confirmar y se
  cobra (la conciliación atiende un doble cobro raro). En línea sigue esperando. Plazo de 8 s en Stripe.
- **P10.** `ReembolsarPagoTenant::automatica`: lo que no se pudo devolver solo abre `devolucion_pendiente`;
  lo cobrado en caja de una cita cancelada abre `devolver_en_caja`. Ambas con `excedente_minor`.
- **P11.** `warning_closed` cierra sin pérdida. **P12.** 30 intentos en unas 26 h, `--reintentar-fallidos`,
  y la revisión nocturna lee el contracargo (Stripe y Mercado Pago; OpenPay por confirmar en sandbox).
- **P13.** Una reserva viva o atendida del derecho cuenta como uso; al retirar, las futuras se cancelan,
  antes de bloquear los derechos (el orden único de candados pone la reserva antes que el derecho).
- **P14.** El candado es de la membresía antes de leer su proceso; pagar cierra todas las abiertas.
- **P16.** Baja ARCO: se desactiva por cada acuerdo; un cobro de un acuerdo cancelado va a Por conciliar.
- **P18.** Porcentaje y mínimo sobre anticipo + resto, restando del resto; lo que no cabe en el resto baja el anticipo sin pagar. **P19.** Una orden en $0 se cumple
  sin pasarela ni caja (`CobrarOrdenTenant::sinCosto`); el aviso de apartado lleva el total con cupón.
- **P20.** Un prepagado se usa con cualquier política del servicio (no se limitó el catálogo).
- **P23.** Mercado Pago: intento sin cobro tras 24 h se cierra y el cargo vuelve a ser cobrable. **P24.**
  Pago a mano de renta: cierra el intento abierto o se rechaza; OpenPay queda vigilado. **P25.** Lo que sigue
  al pago se reintenta en cola (`AplicarDespuesDelPagoRenta`) y los mensajes pagados se resincronizan.
- **Bajas.** a) métodos en línea en el público; b) un pago que falla no corta la conciliación; c) incidencia
  `pago_sin_confirmar` fuera de la ventana; d) `reembolsos.referencia_externa` con índice, no único (la devolución ya salió al guardarla: un choque la dejaría sin registrar; el candado del pago evita duplicarla); e) OXXO respeta
  `cobranza.dias_pagar_en_tienda` en Stripe; f) no se abre un intento `ventanilla` por API; g) anular una
  venta devuelve el uso del cupón; h) las últimas piezas llevan los centavos; i) el ticket no muestra cobrado
  un servicio anulado; j) lo apartado se libera a `apartados.horas_para_recoger`; k) `clave_intento` en el
  ajuste de inventario; l) restaurar un cupón empieza sus usos en cero; q) devolver un paquete con extras
  abre incidencia; s) un intento de OpenPay reemplazado se sigue vigilando; t) la alerta encuentra el cargo
  de Checkout y un importe distinto alerta; u) el pago al momento corre todo lo que sigue; y) el tipo de cambio redondea y tolera la carrera.
- **P26x.** La mensualidad de la app con marca se cobra al precio guardado al encenderla (`estudios.app_marca_mensual_minor`; lo vigente se rellenó con el precio de entonces) y se emite cada mes desde `app_marca_desde`, sin saltarse ninguno e idempotente.

## Consecuencias

- Migraciones: `tenant/2026_10_31_000208_revision_de_dinero`, `2026_10_31_000420_suspensiones_de_estudio` y `2026_11_01_000421_precio_de_app_marca`.
- Parámetros: `cobranza.dias_compra_sin_pagar`, `apartados.horas_para_recoger`, `referidos.meses_gratis_por_anio`.
- Un negocio solo en caja verá moras donde antes no: es lo decidido. Sus avisos usan la frase de `SinPagoEnCaja`.
- Sin asistencia registrada no hay comisión: marcar «llegó» ahora paga al profesional.

## Pendiente

- P2: nada de esto se ha probado contra pasarelas reales; faltan los casos 1–8 con llaves sandbox.
- P3: la app móvil sigue mandando la llave fija y debe tratar el checkout vacío como error (no se tocó `apps/mobile`).
- P26t: la ayuda del superadmin (`es-MX.ts`) no pide `charge.refunded` ni `charge.dispute.created` (archivo en revisión).
- La reversión de P26w solo cubre la baja del invitado; si deja de pagar sin darse de baja no se revierte, porque el beneficio nace ya con su primer mes pagado.

## Salida a producción con las pasarelas comprobadas

Decisión del dueño (2026-10-31): se abre solo con las pasarelas comprobadas contra el
proveedor real. `PASARELAS_VALIDADAS` en `api.env` las lista; una que no esté no se ofrece a
los negocios ni se puede encender. Cada una se agrega al pasar los casos 1 a 8 de
`docs/VERIFICACION-V1.md` y una compra real con su reembolso.
