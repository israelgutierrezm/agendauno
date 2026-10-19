# ADR 0166 — Revisión operativa por áreas

Estado: Aceptado (2026-10-19). Corrige lo que encontró la revisión por áreas del dueño
(inicio, agenda, fichas, paquetes, caja, nómina, comunicaciones, reportes,
configuración y la suscripción). Mejora lo existente; no agrega módulos. Lo que pedía
una entidad nueva (cobro mixto, cierre de caja con conteo) queda para que lo decida el
dueño.

## Inicio del dueño: el contador abre lo que cuenta

- `GET /inicio/hoy` acepta `sucursal_id`, la sucursal de la barra. Solo se aceptan sedes
  permitidas.
- «Por cobrar» no cuenta las citas a las que el cliente no vino, como en recepción.
- «Seguimientos» se cuenta con el alcance de la lista de tareas:
  - sus sedes;
  - sin `tareas.equipo`, solo las suyas.
- Las tarjetas de citas y clases llevan a la jornada en recepción, no a la agenda sin
  fecha. En recepción de citas, el filtro «Activas» (antes «Todas») no muestra las
  canceladas; tienen su propio filtro.

## Alta inicial

- «Recibe reservas» en citas busca un servicio que se pueda agendar en línea (que se
  paga al agendar). Los de bono solo los toma quien ya tiene uno.
- La comprobación «cobro» lleva a donde se cobra (agenda o ventas), no a pasarelas: se
  cumple también con un cobro en caja.
- «Prueba» exige una reserva hecha por un cliente desde la página o su cuenta
  (`onboarding_pasos.prueba`), o que el negocio ya opere. Una reserva del equipo no la
  cumple.
- «Prueba» y «publicar» llevan al paso de publicación, donde está «Ver como cliente».

## Agenda y recepción

- Estado de atención `apartada`: una cita apartada en línea que espera su pago. Ya no
  se presenta como agendada en verde. Lo comparten el servidor, la web y la app.
- El pago de una cita cancelada no se presenta como pagado ni por cobrar. La cita
  conserva a su titular (su última reserva) para el panel y su historial.
- `GET /reservas/{reserva}/inasistencia`: si al marcar que no vino pierde el crédito, se
  tolera o su política no cobra las faltas. La confirmación dice eso, no «puede perder».
- `POST /reservas/{reserva}/cancelar` devuelve `meta.efecto`. El panel deja a la vista
  qué pasó con el crédito, el pago o el anticipo.
- Cobrar desde una clase y mover una clase dejan su resultado visible.
- Las tarjetas chicas de la agenda por profesional dicen en texto (título y etiqueta
  accesible) su estado y si falta cobrarlas. Llevan la marca de la moneda y su leyenda.
- El roster de una clase dice «Por cobrar {monto}», el adeudo y los documentos.

## Fichas de clientes

- Cada plan de la ficha dice dónde vale, su actividad y a qué clases aplica.
- La próxima reserva dice su sede y con quién.
- El resumen del modal de Miembros dice lo que debe (o que está al corriente).

## Paquetes y membresías: lo vendido no cambia

- `lineas_orden.plan` guarda el plan como se vendió al crear la orden. Una orden que se
  paga después concede eso, aunque el plan se haya editado entre la compra y el pago.
- `acuerdos.cobra_anualidad` fija si su plan cobraba la anualidad al contratarlo. Los
  contratados antes (null) se leen del plan, como hasta ahora.
- La venta rápida de la ficha y recepción dice lo mismo que Ventas:
  - qué incluye, dónde vale y sus reglas;
  - la inscripción que se suma, dentro del total que se confirma;
  - las condiciones que hay que aceptar.
- El portal dice a qué clases aplica, si se renueva y si cobra la anualidad.

## POS, inventario y caja

- El mostrador bloquea los artículos en el mismo orden (por id): dos ventas con los
  mismos artículos en otro orden no se bloquean entre sí.
- Al cobrar un presupuesto se bloquea el artículo antes de descontarlo. Si se anula el
  cobro o se devuelve completo, sus artículos vuelven a la sede de la orden.
- Si otra venta se llevó las últimas piezas, el mostrador vuelve a leer las
  existencias.
- Corregir la forma de pago pide motivo, porque cambia cómo cuadra el corte.
- `GET /pagos` dice aparte `por_conciliar` (una incidencia abierta) y `comprobante`
  (`enviado`, `fallido` o `pendiente`).

## Nómina

La tarifa nueva de una persona no puede valer dentro de un periodo que ya se cerró en
un corte (422 en `vigente_desde`). Si no, el corte mostraría como diferencia por pagar
un periodo ya pagado.

## Fichas de datos

- Una ficha puede nacer en borrador (`borrador: true`; la web siempre lo hace).
  `POST /formularios/{formulario}/publicar` la publica si ya tiene preguntas. Un
  borrador no se pide ni se responde.
- Con respuestas, guardar un cambio que crea versión nueva se confirma con
  `confirmar_nueva_version`. Sin confirmar, el API responde 409
  `FORM_NEW_VERSION_UNCONFIRMED` y no cambia nada.
- Quien no diseña fichas solo ve las publicadas que le tocan, sin su conteo de
  respuestas.

## Comunicaciones

- `GET /comunicaciones/alcance` dice, antes de enviar, a cuántos llega por ese canal y
  quién queda fuera: no quiere promociones, sin correo o sin la app. Usa la misma regla
  que el envío.
- El historial separa lo que agotó sus intentos (`fallidos`) de lo pendiente.
- Crear una campaña toma un candado por su contenido: un doble envío no la duplica.
- `MensajeTenant::esPromocional()`: solo lo promocional respeta «no quiero promociones».

## Reportes

- La ocupación del resumen no cuenta las clases canceladas.
- Ocupación e inasistencias dicen su base («12 de 40»).
- El periodo dice la zona horaria.
- El embudo se acota a las sedes de quien lo pide.
- En cohortes, el mes de actividad es el de la clase, no el del registro de asistencia.

## Configuración

- El editor de parámetros protege los cambios sin guardar y muestra cada error junto a
  su campo.
- Archivar un plan y archivar una ficha se confirman como acción destructiva.
- Los diálogos y paneles usan `92dvh`: en el teléfono, el teclado no tapa su pie.

## Suscripción del negocio

- `estudios.reactivado_en`: al reabrir un negocio suspendido, por pago o a mano, lo que
  pasó mientras estuvo cerrado no se le cobra a sus clientes:
  - las faltas automáticas cuentan desde que reabrió;
  - la gracia de los socios en mora se recorre lo que duró cerrado.
- `docs/DESPLIEGUE.md` explica:
  - cómo restaurar un solo negocio sin apagar la plataforma;
  - cómo reactivarlo;
  - qué hacer con un cargo de renta que no se cobró.

## Pendiente de decidir (dueño)

- **Cobro mixto** (efectivo más tarjeta en una venta): pide pagos por venta y una
  migración.
- **Cierre de caja con conteo**: contado por forma de pago, diferencia con motivo y
  quién lo hizo. Es una entidad nueva.
- **Devolución parcial en el mostrador.**
- **La anulación como movimiento del día en que ocurre**, en lugar de quitar la venta
  del corte de su día. Va junto con el cierre de caja.
