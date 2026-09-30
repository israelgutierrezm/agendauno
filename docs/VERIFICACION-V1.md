# Verificación de la V1: pagos y avisos de punta a punta

Guía para comprobar a mano, con llaves de prueba de Stripe, Mercado Pago y OpenPay,
que el dinero y los avisos llegan a donde deben antes de abrir. Complementa a
`agendauno:verificar-produccion` (configuración) y a `agendauno:verificar-concurrencia`
(carreras en MySQL); ver `docs/DESPLIEGUE.md`.

Cada caso dice qué hacer y qué debe verse. Marca cada casilla por pasarela.

## Preparación

1. **Un servidor alcanzable desde internet.** Las pasarelas mandan sus avisos
   (webhooks) a `APP_URL`. Sirve el VPS de staging o un túnel (Cloudflare Tunnel,
   ngrok) hacia la API local. `APP_SPA_URL` debe ser la dirección de la web: ahí
   regresa el cliente después de pagar.
2. **Cola y programador corriendo** (`worker` y `scheduler`). Sin ellos no salen
   correos, no se publican eventos y no vencen las reservas por pagar.
   `agendauno:latido --verificar=cola` y `--verificar=programador` deben salir OK.
3. **Correo real** (`MAIL_*`) y `php artisan agendauno:probar-correo tu@correo.com`
   recibido. Opcional: push con `FCM_CREDENTIALS` y `FCM_PROJECT_ID`.
4. **Un negocio de prueba** registrado y operativo, con:
   - una clase grupal con cupo y un servicio de cita con **pago para reservar**
     (Catálogo, `politica_reserva = pago`, con precio);
   - un paquete o membresía a la venta, y uno que se renueve (reinicio por
     calendario o aniversario);
   - una alumna con cuenta (para el portal) y su correo a la mano.
5. **Una sola pasarela activa a la vez.** Con varias activas, el portal y la página
   pública usan la primera en este orden: Stripe, Mercado Pago, OpenPay.

### Llaves de cada pasarela (Pagos → Pasarelas de pago)

Modo **Pruebas**. La pantalla muestra la «URL para avisos (webhook)» de cada
pasarela: `APP_URL/api/v1/webhooks/tenant/{negocio}/{stripe|mercadopago|openpay}`.

| Pasarela | Llaves | Webhook |
|---|---|---|
| Stripe | `secret_key` (`sk_test_…`) y `webhook_secret` (`whsec_…`) | Dashboard → Developers → Webhooks. Eventos `checkout.session.*`, `payment_intent.*`, `refund.*`. |
| Mercado Pago | `access_token` (`TEST-…`) y `webhook_secret` | Tus integraciones → Webhooks, temas «Pagos» y «Planes y suscripciones». La cuenta vendedora y la compradora de prueba deben ser del mismo país. |
| OpenPay | `merchant_id`, `private_key`, `public_key`, `webhook_user`, `webhook_password` | Guarda usuario y contraseña **antes** de registrar el webhook en el panel; OpenPay manda un código que aparece en la pantalla de Pasarelas y se captura en su panel para verificarlo. |

Tarjetas de prueba: usa las de la documentación de cada pasarela. En Stripe,
`4242 4242 4242 4242` se aprueba, `4000 0000 0000 0002` se rechaza y
`4000 0025 0000 3155` pide autenticación. En Mercado Pago el nombre del titular
decide el resultado (`APRO` aprobado, `OTHE` rechazado, `CONT` pendiente).

**Límite conocido de Mercado Pago:** los pagos hechos con credenciales de prueba no
siempre mandan webhook. Usa «Simular notificación» en el panel de webhooks, con el
id del pago.

### Dónde mirar

- **Cobranza** (`/cobranza`): pagos, reembolsos, «Por conciliar» (pagos tardíos,
  dobles y reembolsos inciertos), «En mora» y «Próximas renovaciones».
- **Ventas** (`/ventas`): órdenes y su estado.
- **Comunicación → Bandeja de salida** (`/comunicaciones`): cada correo o push con
  su estado (`encolado`, `enviado`, `fallido`), intentos y último error.
- **Portal de la alumna** (`/mi-cuenta/pagos`): «Por pagar» y lo comprado.
- El correo del superadmin (`ALERTAS_CORREO`): incidencias de cobro y avisos que
  agotaron sus intentos.

Los avisos tardan 1–2 minutos: el evento se publica cada minuto y los mensajes se
envían cada minuto.

## Casos

### 1. Pago aprobado

- [ ] **Compra en el portal.** La alumna compra un paquete en `/mi-cuenta/pagos` y
  paga con tarjeta aprobada. Vuelve a `/mi-cuenta?pago=exito`.
  - Orden `pagada`, pago `aprobado`, el paquete aparece con sus clases.
  - Llega el recibo (`orden.pagada`).
- [ ] **Reserva de clase con pago.** Reserva una clase con pago para reservar: la
  reserva queda `pendiente_pago` y aparta el lugar. Al pagar pasa a `confirmada`.
  - Llegan la confirmación (`reserva.confirmada`) y el recibo.
- [ ] **Cita desde la página pública** (`/agendar/{negocio}`, sin cuenta). Elige
  servicio, profesional y hueco; paga. La cita queda confirmada y el profesional la
  ve en su agenda.
- [ ] **OXXO** (Stripe u OpenPay): se muestra la referencia o el voucher. Al
  «pagar» en el simulador de la pasarela, la orden pasa a `pagada`.

### 2. Pago rechazado

- [ ] Paga con una tarjeta que se rechaza. La pasarela muestra el error y deja
  reintentar; la orden sigue `pendiente` en «Por pagar».
- [ ] Reintenta con una tarjeta aprobada: se paga una sola vez (un pago
  `aprobado`, sin incidencia de cobro doble).

### 3. Pago abandonado

- [ ] Reserva una clase con pago y **no pagues**. Pasados los minutos de
  «Reglas de la agenda → minutos para pagar» (30 por defecto; bájalo a 5 para la
  prueba):
  - la reserva queda `cancelada` («venció el pago») y el lugar se libera (o se
    ofrece a la lista de espera);
  - la orden queda `cancelada`;
  - en Stripe la sesión de pago queda expirada y en Mercado Pago la preferencia
    vence. OpenPay no permite cancelar: el intento solo se cierra de nuestro lado.
- [ ] Una compra de paquete sin reserva no vence de nuestro lado: sigue en
  «Por pagar».

### 4. Confirmación tardía

- [ ] Reserva con pago, espera a que venza y **después** termina de pagar:
  - OpenPay: deja abierta su página de pago, espera a que venza la reserva y paga;
  - Stripe: detén el `scheduler` (si no, la conciliación del caso 6 confirma el pago
    antes de que venza), paga con el webhook apagado, vuelve a encender el
    `scheduler`, deja vencer la reserva y reenvía el evento desde el dashboard
    («Resend»).

  Resultado esperado:
  - si la clase no ha empezado y el lugar sigue libre, la reserva se reconfirma sola;
  - si no, el pago queda aprobado sobre una orden cancelada, aparece una incidencia
    «pago tardío» en «Por conciliar», le llega aviso a la alumna y al equipo
    (`pago.tardio`) y el superadmin recibe la alerta. Resuélvela con «devolver».

### 5. Notificación duplicada

- [ ] Reenvía desde la pasarela el mismo aviso de un pago ya aprobado (Stripe
  «Resend»; Mercado Pago «Simular» dos veces).
  - La API responde 200 las dos veces y no pasa nada más: no se duplican paquetes,
    créditos, reservas ni correos.
- [ ] Paga dos veces la misma orden (dos pestañas del checkout). El segundo pago
  queda como incidencia «cobro doble» en «Por conciliar»; se resuelve con un
  reembolso.
- [ ] Un aviso con firma incorrecta (por ejemplo, cambia el `webhook_secret`) se
  rechaza con 400 (Stripe) o 401 (Mercado Pago, OpenPay) y no toca nada.

### 6. Aviso perdido

`agendauno:conciliar-pagos` (cada 5 minutos) le pregunta a la pasarela por los cobros
en línea sin confirmar desde hace más de 5 minutos, y por los intentos que se
cerraron de nuestro lado sin que la pasarela lo confirmara (por ejemplo, un pago en
tienda de OpenPay al reintentar con tarjeta). Aplica lo mismo que habría aplicado el
aviso; nunca cobra ni cancela.

- [ ] Quita (o rompe) el webhook en el panel de la pasarela y paga una compra.
  - En 5–10 minutos la orden queda `pagada` y llega el recibo, sin aviso.
  - La bitácora registra «pago.conciliado» y el superadmin recibe una alerta
    «aviso de pago perdido» (una por negocio y pasarela).
- [ ] Reserva con pago y paga con el webhook roto: la reserva queda confirmada
  antes de que venza el apartado.
- [ ] Paga en tienda (OXXO) con el webhook roto: se sigue preguntando, cada vez más
  espaciado (cada vuelta la primera hora, cada 30 minutos el primer día, cada
  2 horas después) mientras la referencia esté vigente; al pagarse, se confirma.
- [ ] Deja vencer una sesión sin pagar: el intento queda cerrado y la compra sigue
  en «Por pagar».
- [ ] Restaura el webhook y reenvía un aviso ya conciliado: no se duplica nada.

### 7. Reembolso y conciliación

- [ ] **Total.** En Cobranza → Pagos → «Reembolsar» un paquete sin usar, con
  «revertir créditos». El pago queda `reembolsado`, las clases del paquete vuelven a
  cero en el libro y la orden se cancela. El dinero aparece devuelto en la pasarela.
- [ ] **Paquete ya usado.** Un reembolso total de un paquete con clases usadas se
  rechaza; uno parcial revierte solo la parte proporcional sin usar.
- [ ] **Pendiente.** Si la pasarela deja el reembolso pendiente, se cierra solo con
  su aviso o con `agendauno:conciliar-reembolsos` (cada 5 minutos). Si no se puede
  saber cómo quedó, aparece «reembolso incierto» en «Por conciliar».
- [ ] **OpenPay OXXO** no admite reembolso en línea: se registra como devolución
  manual (en caja o fuera del sistema).
- [ ] **Corte de caja.** Lo cobrado y lo devuelto del día cuadra con lo que muestra
  el panel de la pasarela.

### 8. Renovación de membresía

No hay forma de adelantar el reloj desde los comandos: para probar sin esperar, en
la base del negocio de prueba pon `acuerdos.proxima_cobro_en` en la fecha de hoy y
corre el comando a mano.

- [ ] **Aviso previo.** Con la renovación a 3 días o menos,
  `php artisan agendauno:avisar-renovaciones` manda «renovación próxima» y, a quien
  paga a mano, le deja la orden de renovación en «Por pagar».
- [ ] **Pago automático con tarjeta guardada (Stripe).** La alumna autoriza su
  tarjeta en «Pago automático» (vuelve con `?tarjeta=exito`). Con la renovación
  vencida, `php artisan agendauno:cobrar-suscripciones` cobra sin que ella haga nada;
  la membresía avanza al siguiente periodo y llega el recibo.
- [ ] **Suscripción (Mercado Pago / OpenPay).** El cobro lo hace la pasarela; el
  comando solo concilia lo que ya cobró. Si en los días de gracia no llega el cobro,
  empieza la mora.
- [ ] **Rechazo.** Con una tarjeta que se rechaza (en Stripe, cámbiala por
  `4000 0000 0000 0341`, que se guarda pero falla al cobrar), el cobro abre la mora:
  aparece en «En mora», llega «cobro fallido» y se reintenta a los 1, 3 y 7 días. Al
  vencer la gracia, `php artisan agendauno:escalar-dunning` suspende la membresía.
  Pagar la deuda la regulariza.
- [ ] **Sin pago automático.** La renovación queda en «Por pagar» y en «En mora»
  («Falta completar el pago en línea») hasta que la alumna paga.

### 9. Correos, recordatorios y push

- [ ] Cada caso anterior dejó su mensaje `enviado` en la Bandeja de salida y llegó
  al correo (revisa también spam: SPF y DKIM del remitente).
- [ ] **Recordatorios.** Reserva una clase para dentro de ~24 h y otra para dentro
  de ~2 h: `agendauno:enviar-recordatorios` (cada 5 minutos) manda un recordatorio
  por reserva y momento, una sola vez.
- [ ] **Cancelar y reprogramar** una reserva: llegan «reserva cancelada» y
  «reserva reprogramada» a la alumna y, si tiene la app, el push al profesional.
- [ ] **Falla de correo.** Con credenciales SMTP inválidas, el mensaje queda
  `fallido` tras 6 intentos y el superadmin recibe «correo fallido». Al corregirlas,
  los nuevos salen.
- [ ] **Push** (si FCM está configurado): la app con sesión iniciada recibe la
  confirmación, el recordatorio de 2 h y la cancelación de una clase.
- [ ] **WhatsApp** (ADR 0069). Con WhatsApp apagado en la plataforma, el negocio
  no ve el canal. Enciéndelo con el número y el token, y manda la prueba
  (`hello_world`). Luego enciende «Reserva confirmada» por WhatsApp en Automáticos.
  Agenda en la página pública con celular y marca la casilla: llega el WhatsApp.
  Apágalo con un aviso en cola: queda `descartado`.
- [ ] **Estados de WhatsApp** (ADR 0074). En la app de Meta, configura el webhook
  con la dirección y el token de «Webhook de estados» y suscríbete a `messages`;
  guarda el App Secret. Al llegar el WhatsApp de una reserva, la salida de
  Comunicaciones dice «Entregado» y, al abrirlo, «Leído». A un número sin WhatsApp
  queda «Falló» con el motivo de Meta y no se reintenta.
- [ ] **WhatsApp del dueño** (ADR 0070). Con «Con los dueños» encendido, registra
  un negocio: en «Contacto» marca «Recibir avisos de AgendaUno por WhatsApp», pide
  el código y escríbelo. Aparece «WhatsApp verificado», y la ficha del negocio en
  el superadmin lo muestra. Apagado, el registro no muestra la casilla.
- [ ] **Avisos al dueño** (ADR 0071). Con la prueba a 3 días o menos de terminar,
  `agendauno:avisar-duenos` manda el correo «Tu prueba gratis de AgendaUno termina
  el …», y el WhatsApp si el dueño lo aceptó. Al emitirse la renta llega «Tu renta
  de … está lista»; si vence sin pagarse, «venció»; al pagarla, «Recibimos tu
  pago». Ninguno se repite, y la ficha del negocio en el superadmin los lista.
- [ ] **Panel del dueño y rentas vencidas** (ADR 0072). En «Renta» →
  «Avisos de AgendaUno», el dueño verifica su WhatsApp con el código y activa o
  desactiva «Recibirlos también por WhatsApp». Captura el correo en Configuración
  del superadmin: al vencer una renta llega la alerta «Renta vencida». En Cobros →
  «Vencidos», «Suspender» suspende el negocio y luego muestra «Negocio suspendido».
- [ ] **Cambiar el WhatsApp del negocio** (ADR 0075). En «Avisos de AgendaUno»,
  «Cambiar» con otro número: llega el código a ese WhatsApp y, al escribirlo, el
  número cambia verificado; la página del negocio muestra el nuevo. Con WhatsApp
  apagado en la plataforma se guarda sin código y queda sin verificar.
- [ ] **Suspensión automática** (ADR 0073). Con una renta vencida hace 12 días,
  el dueño recibe «Tu negocio se suspenderá el …». A los 15,
  `agendauno:suspender-por-renta` lo suspende. La página pública y el panel dan
  404; un alumno no puede entrar y el dueño entra directo a «Renta», con el
  aviso de suspendido. Al pagar, se reactiva solo.

Sin plantilla por defecto (el negocio la crea si la quiere): «pago reembolsado»,
«membresía suspendida» al cliente y «reserva creada».

### 10. Renta del SaaS (la plataforma cobra al negocio)

- [ ] En `/plataforma` activa Stripe con llaves de prueba y registra en Stripe el
  webhook `APP_URL/api/v1/webhooks/plataforma/stripe` (la pantalla aún no lo
  muestra). Hoy solo Stripe cobra la renta.
- [ ] Con un negocio fuera de prueba y un mes cerrado,
  `php artisan agendauno:generar-cargos-renta --periodo=AAAA-MM` crea su cargo. El
  dueño lo paga en «Suscripción» (`/renta`) y el cargo pasa a `pagado`.
