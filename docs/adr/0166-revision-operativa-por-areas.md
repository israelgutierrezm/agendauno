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
- Cada tarjeta de clases abre exactamente lo que cuenta:
  - «Clases» y «Ocupados» abren recepción con las clases activas;
  - «Listas por pasar» y «En espera» abren recepción con ese filtro;
  - «Mañana con poca gente» abre Oportunidades en esa fecha, solo las que van a menos
    de la mitad.
- «Ocupados» usa la regla del cupo (`CupoDeSesionTenant`): reservas que ocupan lugar
  más los lugares de Wellhub y TotalPass, igual que la agenda.
- El inicio muestra los bloqueos del día (profesional, sala o sucursal) de las sedes
  de quien lo ve.

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
- `agendauno:verificar-concurrencia` (MySQL, en CI) prueba con procesos reales:
  - varias ventas a la vez por la última pieza: una pasa y el stock queda en cero;
  - dos ventas con los mismos artículos en distinto orden: las dos pasan, sin
    deadlock.
- Corregir la forma de pago pide motivo, porque cambia cómo cuadra el corte.
- `GET /pagos` dice aparte `por_conciliar` (una incidencia abierta) y `comprobante`
  (`enviado`, `fallido` o `pendiente`).

## Nómina

La tarifa nueva de una persona no puede valer dentro de un periodo que ya se cerró en
un corte (422 en `vigente_desde`). Si no, el corte mostraría como diferencia por pagar
un periodo ya pagado.

Una prueba cubre el pago por asistente: un asistente marcado después de pagar el corte
es diferencia, el ajuste la salda y desmarcarlo la vuelve negativa sin cambiar el
total del corte.

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
- El embudo cuenta las renovaciones que vencían en el periodo, no las órdenes creadas
  en él. La orden de renovación nace días antes, con el aviso, y guarda
  `ordenes.renueva_vence_el`. Las anteriores (null) usan su fecha de creación.
- Rentabilidad usa lo que se cobró, no el precio de hoy:
  - una clase pagada aparte: su total;
  - con paquete: el precio y los créditos con que se vendió (la línea de su orden);
  - sin orden: los del plan;
  - si no hay nada de eso: el precio por clase.

## Configuración

- El editor de parámetros protege los cambios sin guardar y muestra cada error junto a
  su campo.
- Archivar un plan y archivar una ficha se confirman como acción destructiva.
- Los diálogos y paneles usan `92dvh`: en el teléfono, el teclado no tapa su pie.
- Horarios, giro, terminología y región muestran cada error junto a su campo. Región
  protege los cambios sin guardar.

## App

`GET /yo` y el login dicen `estudio.zona_desfase_min`, el desfase de hoy de la zona
del negocio con UTC. La agenda de la app abre en el día de hoy del negocio aunque el
teléfono esté en otra zona, antes de cargar las sesiones, sin un paquete de zonas.

## Suscripción del negocio

- `estudios.reactivado_en`: al reabrir un negocio suspendido, por pago o a mano, lo que
  pasó mientras estuvo cerrado no se le cobra a sus clientes:
  - las faltas automáticas cuentan desde que reabrió;
  - la gracia de los socios en mora se recorre lo que duró cerrado.
- `docs/DESPLIEGUE.md` explica:
  - cómo restaurar un solo negocio sin apagar la plataforma;
  - cómo reactivarlo;
  - qué hacer con un cargo de renta que no se cobró.

## Segunda revisión de R01–R14

Se repasaron los criterios de aceptación de R01–R14 (ADR 0165) contra el código y se
corrigió lo que todavía fallaba.

- **R01:** cambiar de grupo exige que la persona sea del alcance de quien lo hace, como
  al inscribir.
- **R02:** las respuestas de una ficha piden lo mismo que el expediente: `miembros.ver`
  siempre y, las de alguien del equipo, además `usuarios.gestionar`. Así un rol
  anterior a los requisitos no las lee. El contador de respuestas de cada ficha cuenta
  solo las que quien lo ve puede leer.
- **R03:**
  - Cerrar un grupo antes de una baja futura adelanta esa baja al cierre: no conserva
    ni se le cobran fechas de un grupo cerrado.
  - Una baja futura se puede adelantar o cambiar de grupo, pero no posponer (409).
  - Volver a inscribir a quien sigue inscrito conserva su alta: no adelanta el alta de
    un cambio de grupo.
- **R04:** dentro de la transacción, con la cita bajo candado, se vuelve a buscar el
  intento: dos peticiones idénticas a la vez devuelven el mismo cobro. En Tendencias,
  el comprobante de solo el servicio no cuenta como venta.
- **R05:** en el mostrador, la venta previa se devuelve solo después de revisar la sede
  y al comprador.
- **R06:**
  - Se puede pedir el ticket al cobrar solo el servicio y, si no se pidió, mandarlo
    desde el comprobante.
  - El correo sale por la cola: `ventas_pos.ticket_solicitado_en` lo deja `pendiente`,
    y el propio correo marca `enviado` al salir o `fallido` al agotar sus intentos.
  - Reenviar tiene tope.
- **R07:** tras un conflicto, el carrito de la cita toma el precio y el stock de ahora.
  Una cantidad mayor a la de la sucursal se dice y no se cobra.
- **R08:**
  - El cambio de grupo bloquea los dos grupos en orden de id antes de tocar sus fechas,
    y se reintenta ante un deadlock.
  - Un error de base de datos al reservar dentro del cambio deshace todo el cambio; ya
    no se toma como «no reservada».
  - La bitácora del cambio y la vista previa sin eventos quedan probadas.
  - `agendauno:verificar-concurrencia` (MySQL, en CI) prueba dos cambios opuestos a la
    vez y dos cobros iguales a la vez.
- **R09:** solo los ajustes que corrigen una diferencia (`compensa_diferencia`, el
  botón «Ajustar») se restan de lo que falta. Un bono va aparte. Los anteriores (null)
  cuentan como corrección, como hasta ahora.
- **R10:** la app muestra días y horas en la zona de la sucursal o del negocio:
  - el inicio pide el día del negocio;
  - en un día vacío la agenda usa el día del negocio;
  - Mis clases;
  - los huecos libres (`siguiente_local`);
  - el portal del cliente;
  - el desfase se actualiza al cambiar de rol.

  «Agregar a mi calendario» sigue con el instante real.
- **R11:** el embudo de citas suma «interés → cita confirmada» y no cuenta las
  canceladas como confirmadas. Queda probado en el API.
- **R12:** el seguimiento automático (sin asistir y primera asistencia), el radar de
  renovaciones y el paso compra → asistencia del embudo usan la fecha de la clase.
- **R13:**
  - La evidencia de una pasarela es un pago creado con su configuración vigente.
  - «Cobrar en línea» del quickstart pide una pasarela en línea comprobada.
  - La llave de avisos que genera el negocio en Wellhub renueva `configurada_en`.
- **R14:**
  - Sin referencia, una cita movida ese mismo día no se agrega en silencio.
  - Un celular de varias fichas pide el correo o el número de cliente.
  - Lo pagado afuera queda en `ordenes.pagado_fuera_minor`, y una cita pagada toda
    afuera aparece como pagada.
  - Quedan probados la referencia repetida, el cambio de profesional, el número de
    cliente en conflicto, que no hay ingreso nuevo y el resumen de la web.

Sin cambiar a propósito:
- Las fechas solo día (vencimientos, pausas) las calcula el servidor.
- En el portal, una sucursal en otra zona que la del negocio se muestra en la hora del
  negocio: el API del portal no manda el desfase de cada sede.

## Pendiente de decidir (dueño)

- **Cobro mixto** (efectivo más tarjeta en una venta): pide pagos por venta y una
  migración.
- **Cierre de caja con conteo**: contado por forma de pago, diferencia con motivo y
  quién lo hizo. Es una entidad nueva.
- **Devolución parcial en el mostrador.**
- **La anulación como movimiento del día en que ocurre**, en lugar de quitar la venta
  del corte de su día. Va junto con el cierre de caja.
