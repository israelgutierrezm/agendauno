# ADR 0227 — Enlace app→web de un solo uso, reconsultar pagos, mensajes sin confirmar y lo que es de todo el negocio

Estado: Aceptado (2026-10-26). Corrige hallazgos de la revisión para V1 sobre la ADR 0211
(enlace de la app a la web), la 0181/0209 (devoluciones hechas en la pasarela), la 0205/0209
(envío de mensajes una sola vez) y la 0098 (personal acotado a sus sucursales).

## Contexto

- **Enlace app→web.** El código de un solo uso se canjeaba con `Cache::pull`, que lee y
  borra en dos pasos: dos canjes simultáneos podían abrir dos sesiones web. Además el
  código seguía sirviendo aunque se cerrara la sesión de la app que lo pidió.
- **Devoluciones hechas en el panel de la pasarela.** La revisión nocturna solo mira los
  cobros de los últimos días; una devolución hecha en el panel de Stripe/Mercado Pago/
  OpenPay a un cobro más viejo, con su aviso perdido, nunca se registraba.
- **Mensajes con resultado incierto.** Un error de red con el mensaje ya mandado (se agotó
  el tiempo esperando la respuesta) quedaba «fallido» y se reintentaba: el alumno podía
  recibirlo dos veces. Lo que se anota después de mandarlo (el saldo de WhatsApp, el
  `wamid`) también podía volverlo fallido. Y lo que una pasada cortada dejaba «enviando»
  terminaba como «fallido», aunque quizá sí salió.
- **Opciones que terminan en un rechazo.** La bitácora y la conciliación de plataformas
  son de todo el negocio y el API niega a quien solo ve sus sucursales, pero el menú se
  las ofrecía igual (con el permiso `auditoria.ver` bastaba).

## Decisión

1. **Enlace web atómico y ligado a la sesión.** Se lee el código y solo lo canjea quien
   logra borrarlo (`Cache::forget` es atómico en Redis y en la base): el segundo canje
   simultáneo recibe «no válido». El código guarda el id del token de la app que lo pidió;
   si esa sesión se cerró o se revocó, ya no sirve. Sigue valiendo pocos minutos.
2. **«Reconsultar este pago».** `POST /pagos/{pago}/reconsultar` (permiso
   `pagos.reembolsar`, 10 por minuto): pregunta a la pasarela las devoluciones del cobro y
   registra las que falten, idempotente con el aviso. Solo cobros en línea. En Cobranza,
   botón «Reconsultar este pago» en cada cobro en línea aprobado. Para revisar muchos a la
   vez, `agendauno:conciliar-reembolsos --desde=AAAA-MM-DD [--hasta=AAAA-MM-DD]` revisa
   todos los cobros de esas fechas, sin el tope de cada pasada nocturna.
3. **Tres resultados de un envío.** Además de «enviado» (el proveedor lo aceptó) y
   «fallido» (no salió: no se pudo conectar o el proveedor lo rechazó; se reintenta hasta
   el máximo), un mensaje queda **«sin confirmar»** (`incierto`) si pudo haber salido: se
   agotó el tiempo o se cortó la conexión a media entrega, o una pasada cortada lo dejó
   «enviando». Un mensaje sin confirmar **no se reintenta solo** (un aviso repetido es peor
   que uno revisado): en la bandeja de salida se filtra, dice el motivo y se puede
   «Reenviar» (`POST /mensajes/{m}/reenviar`, permiso `comunicaciones.gestionar`), que lo
   devuelve a la cola. Lo que se anota después de que el proveedor lo aceptó (saldo de
   WhatsApp, `wamid`) ya no lo vuelve fallido: se reporta el error y queda enviado. Las
   campañas cuentan los sin confirmar aparte y el recibo de un cobro puede verse «sin
   confirmar» en Cobranza.
4. **Lo que es de todo el negocio no se ofrece a quien está acotado.** La sesión trae
   `todo_el_negocio` (false si el usuario solo ve algunas sucursales). La política de
   acceso de la web tiene `todoElNegocio`: la bitácora la usa, y la conciliación de
   plataformas no se muestra en el reporte de plataformas. El API sigue negándolas (403).

## Consecuencias

- Pruebas: enlace web (vence, se usa una vez, deja de servir al cerrar la sesión),
  reconsultar un cobro de hace 40 días, mensajes sin confirmar (tiempo agotado → sin
  confirmar, no se repite, se reenvía; sin conexión → fallido y se reintenta), y la
  política de acceso de la bitácora.
- Clasificar el error es por su tipo y su texto (cURL 6/7, «could not be established»:
  no salió; tiempo agotado o conexión perdida: pudo haber salido). Un proveedor con otros
  mensajes de error cae en «fallido» (se reintenta), como antes.
- Quien reenvía un mensaje sin confirmar acepta que quizá llegue dos veces; por eso es a
  mano y con el motivo a la vista.
