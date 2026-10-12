# ADR 0145 — A quién se le vende en el mostrador

Estado: Aceptado (2026-10-12). Pedido: «quita el "para" de la propina. Lo que me
interesaría es poder vender a un alumno/miembro o un instructor/maestro hasta un
administrador propio o público en general … un check para enviar su ticket por correo
y tener el registro de a quién se le vendió, así como que sus compras [las vean] los
usuarios. Si es público en general no se envía ticket y se registra como venta a público
en general». Y: «a las ventas recientes hay que agregar paginación y filtros para fechas».

## Contexto

- La venta del mostrador (R21, ADR 0089, ADR 0139) no decía a quién se le vendió. La
  propina podía decir para quién del equipo era (`propina_para_id`).
- Persona ≠ Usuario: alumnos y clientes tienen persona, pero alguien del equipo puede no
  tenerla nunca (solo su cuenta).
- «Ventas recientes» traía las últimas 100 sin páginas ni fechas, y los indicadores
  del día se calculaban en el navegador con esas 100.

## Decisión

**A quién se le vende.**

- Cada venta es a una persona del negocio o a público en general:
  - `ventas_pos.persona_id`, nulo para público en general (lo que pasa si no se elige a
    nadie);
  - `ventas_pos.ticket_enviado_en`: cuándo se le mandó su ticket.
- Se le puede vender a cualquier persona del negocio:
  - alumnos o clientes;
  - instructores;
  - el equipo, también el propietario o un administrador.
- `GET /pos/compradores?q=` (`pos.vender`) busca por nombre, correo o celular, con la
  misma búsqueda del directorio:
  - una persona llega como `persona:<ulid>`;
  - alguien del equipo sin ficha llega como `usuario:<ulid>`.
- Al venderle a alguien del equipo sin ficha se le crea su persona, no facturable
  (`PersonaDeUsuarioTenant::asegurar`, tipo instructor o staff): no cuenta como alumno.
- Alcance por sucursal (R19): quien vende acotado a unas sedes solo encuentra (y solo
  puede elegir) a los alumnos de esas sedes. Al equipo lo encuentra completo.

**El ticket por correo.**

- Solo si quien cobra lo marca, la venta es a una persona y esa persona tiene correo.
  - Con una persona sin correo, la API no cobra (422 `enviar_ticket`).
  - A público en general nunca se le manda, aunque se pida.
- En la pantalla, la casilla aparece marcada al elegir a alguien con correo y dice a
  dónde va.
- Se manda en cola (`CorreoTicketVenta`) después de registrar la venta:
  - lleva folio, fecha en la zona del negocio, productos con sus extras, propina,
    total, forma de pago y, en efectivo, lo recibido y el cambio;
  - si el correo no sale, la venta queda registrada igual y sin marcarse como enviada.
- El folio es el final del ulid: no expone el consecutivo.

**Sus compras.**

- `GET /mi/compras-mostrador` (por páginas) devuelve los tickets de quien tiene la
  sesión, o de la persona a su cargo (B05). Sirve a alumnos y al equipo.
- Se ven en «Pagos» (alumno) y en «Mi perfil» (equipo); sin compras, la sección no
  aparece. Las anuladas se ven como anuladas.
- En la ficha del alumno, «Compras en mostrador» (`GET /pos/ventas?persona_id=`), para
  quien puede ver el inventario.

**Ventas recientes.**

- `GET /pos/ventas` va por páginas (`page`, `per_page` hasta 100; 20 por omisión) y
  filtra por:
  - `desde` / `hasta`, días del negocio en su zona horaria;
  - `sucursal_id` (antes se filtraba en el navegador);
  - `persona_id`.
- `meta.resumen` suma lo filtrado y `meta.hoy` lo de hoy en esas sucursales. Las dos
  van sin anuladas y con las propinas aparte.
- Los indicadores del día salen de `meta.hoy`: ya no dependen de cuántas ventas trajo
  la lista.
- Cada venta dice a quién se le vendió (nombre y qué es, con las palabras del negocio y
  en su género), o «Público en general», y si se le mandó el ticket.

**La propina ya no lleva «para quién».**

- Se quitaron el selector, `propina_para_id` y `GET /pos/propina-para`.
- La columna `ventas_pos.propina_para_id` se conserva por lo ya registrado, pero ya no
  se llena ni se muestra.

## Consecuencias

- Migración del negocio `2026_10_12_000135`: dos columnas en `ventas_pos` e índices
  por persona y por fecha.
- Las ventas anteriores quedan como público en general.
- Las demos venden a la mitad de sus clientes. Eligen sin tocar la semilla aleatoria,
  así el resto de su historia no cambia.
- La app móvil no muestra todavía las compras del mostrador.
