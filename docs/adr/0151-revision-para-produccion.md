# ADR 0151 — Revisión para producción: alcance, cobros y entradas públicas

Estado: Aceptado (2026-10-13).

Pedido: revisión profunda de todo el sistema para salir a producción, con los roles de
dueño o administración, instructor y miembro, la app móvil y los formularios. Este ADR
reúne las decisiones de esa revisión que cambian cómo se comporta el sistema.

## Decisiones

**Alcance por sucursal en todo lo que cuelga de un cliente (amplía R19 y ADR 0095).**

`AlcancePersonaTenant` aplica la regla de la ficha del miembro: su sede y, si quien
consulta imparte, solo sus clientes. Una persona del equipo acotada a sus sedes ya no ve
ni opera, ni siquiera con el ULID, nada de un cliente de otra sede:

- expediente, documentos, consentimientos y respuestas de fichas;
- reservas, lista de la clase, asistencia y check-ins de sesiones de otra sede;
- créditos, membresías, bajas, pausas, mora, pago automático y lealtad;
- órdenes, cuotas, incidencias de cobro y facturas.

Las tareas, los mensajes, las reseñas y los canjes se listan solo de sus sedes. Lo que no
cuelga de nadie lo ve todo el equipo, y una tarea asignada la ve siempre quien la tiene.

Quien imparte ve los derechos solo de sus clientes (antes, de cualquier miembro con
`derechos.ver`).

**Permisos.**

- Una llave de API solo lleva permisos de quien la crea, y no la crea alguien acotado a
  sus sedes: la integración ve todo el negocio.
- Timbrar la factura de la renta, comprar timbres y cambiar los avisos de la plataforma
  piden `estudio.gestionar`; antes bastaba `facturacion.ver`, que es de lectura.

**Cobros.**

- **Cupones.** El uso de un código se cuenta al pagarse la orden (fulfillment), no al
  crearla: una compra que nadie paga ya no agota sus usos.
- **Renovaciones.** Un acuerdo que falla al renovar (la pasarela no responde, un dato que
  rechaza) se reporta y no detiene a los demás del negocio.
- **Cargos automáticos sin respuesta.** Si la llamada a Stripe no responde, el intento se
  deshace, aunque el banco sí haya cobrado. El aviso `payment_intent.succeeded` trae la
  orden y la domiciliación en su metadata. Con ellas se registra el pago, siempre que el
  importe y la moneda coincidan con la orden. Si la orden ya estaba pagada, queda como
  cobro doble por devolver.
- **Incidencias de cobro.** «Aplicar» una incidencia ocurre bajo bloqueo de la
  incidencia y de la orden.

**Citas y reservas del cliente.**

- **Duración.** Quien agenda por su cuenta, desde el portal o la página pública, no
  decide cuánto dura la cita: vale la del servicio y, si el servicio no tiene,
  `citas.duracion_defecto`.
- **Reprogramar.** Cambiar una clase a otra fecha respeta la apertura y el cierre de
  reservas del negocio.
- **Fichas de una persona a cargo.** Las fichas de datos se llenan desde la cuenta con
  `POST /mi/formularios/{id}/respuestas`. La persona sale de la cuenta en uso
  (`X-Participante`), nunca del cuerpo. Antes, una persona a cargo recibía 403.

**Entradas públicas.**

- **Captcha.** La cita sin cuenta pasa por reCAPTCHA, como el registro y la captación.
- **Datos de un cliente que ya existe.** Al agendar con el correo de alguien que ya es
  cliente, no se cambia su consentimiento de WhatsApp. El límite de citas por pagar
  responde con un mensaje que no revela si ese correo es cliente ni cuánto debe.
- **Entornos.** Fuera de `local` y `testing`, staging incluido, los avisos sin firma se
  rechazan, el captcha sin llave no se aprueba y el registro no devuelve el token de
  activación. Antes estas aperturas aplicaban a todo lo que no fuera `production`.
- **Inicio de sesión.** Compara siempre contra un hash: el tiempo de respuesta no revela
  si un correo tiene cuenta.
- **Subdominios.** Se reservan más subdominios que podrían usarse para suplantar a la
  plataforma: `pagos`, `login`, `cuenta`, entre otros.

**Operación.**

- **Caché de rutas.** La API arranca con `route:cache`: un nombre de ruta repetido la
  dejaba sin levantar. Una prueba y el CI, que arranca la imagen, lo impiden.
- **Despliegue.** Solo se publica un commit con su CI en verde, y en `main` el CI ya no
  cancela corridas en curso.
- **Contenedores.** Los logs rotan (20 MB × 5). El arranque solo cambia el dueño de lo
  que no es de `www-data`.
- **Base de datos y cola.** MySQL va en UTC. Los trabajos fallidos se guardan 30 días.
- **Respaldos.** Se restauran por flujo, sin cargarlos en memoria.
- **Agenda.** `GET /sesiones` admite hasta 1500 sesiones y avisa con `meta.truncado`
  cuando el rango trae más. También filtra por `oferta_id`.
- **CORS.** Va sin credenciales, porque el API usa bearer.

## Consecuencias

- Un rol propio acotado que antes veía datos de otras sedes por ULID deja de verlos. Es
  el comportamiento que R19 ya prometía.
- `usos_maximos` de un código cuenta compras pagadas. Varias órdenes pendientes a la vez
  pueden pasar un poco el tope al pagarse; se acepta a cambio de que el abandono no lo
  agote.
- Lo que queda pendiente de la revisión (endurecimiento de infraestructura, apps,
  cuentas y llaves del dueño) está en el informe de la revisión, no aquí.
