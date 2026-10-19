# ADR 0161 — Anticipo de una cita: se aparta pagando una parte y el resto queda por cobrar

Estado: Aceptado (2026-10-18). Continúa el ADR 0065 (cómo se cobra la cita que agenda
el cliente) y el ADR 0158 (cobrar al terminar la cita).

## Contexto

Una cita de pago se apartaba pagando todo en línea o quedaba confirmada por cobrar. Los
servicios largos o caros (color, tratamientos, sesiones de una hora) necesitan otra
cosa: que el cliente pague una parte para apartar el horario y el resto al terminar.
Si no, el negocio elige entre pedir todo por adelantado, que espanta, o no pedir nada,
que trae inasistencias.

El dueño decidió (2026-10-18):

- cada servicio define su anticipo como **monto fijo o porcentaje** del precio;
- si el cliente **cancela a tiempo**, el anticipo **se le devuelve**; si cancela tarde
  o no llega, el negocio lo conserva;
- al agendar en línea el cliente **elige pagar el anticipo o todo**.

## Decisión

- **El servicio** (`ofertas`) guarda `anticipo_minor` (monto) o `anticipo_pct` (1 a 99),
  uno u otro, en `PUT /ofertas/{oferta}`:
  - el monto debe ser menor que el precio;
  - elegir uno borra el otro y `null` lo quita.
  - `OfertaTenant::anticipoMinor()` da lo que resulta con el precio de hoy, con el
    porcentaje redondeado a la unidad menor.
  - Si el anticipo llegara a cubrir todo el precio, no hay anticipo: se paga completo.
- **Cuándo se pide**: solo cuando el cliente agenda por su cuenta (página pública o su
  cuenta) y el negocio cobra en línea. Lo que agenda el negocio (recepción) no lleva
  anticipo: queda una sola orden por cobrar, como siempre. El anticipo se pide aunque
  el negocio no exija pagar en línea para confirmar (`citas.pago_en_linea_obligatorio`).
- **Dos órdenes normales**, sin pagos parciales:
  - `reservas.orden_id` es la del **anticipo**. Es la que se paga en línea para
    confirmar, igual que hoy: apartado, vencimiento, pago tardío, fulfillment y
    enlace del correo no cambian.
  - `reservas.saldo_orden_id` es la del **resto**, que queda pendiente. Se cobra al
    terminar (`POST /reservas/{reserva}/cobro` cobra solo esa) o en línea si el
    cliente quiere.
  - Corte, reportes y facturas siguen funcionando porque cada orden es una orden
    normal.
  - Con «pagar todo» (`pagar: todo`) hay una sola orden, como antes.
- **La regla se congela al agendar**: `reservas.anticipo_minor` guarda el anticipo que
  pedía el servicio, también si el cliente pagó todo. Si después cambia el servicio, la
  cita conserva su regla de devolución.
- **Lo que falta cobrar** lo dice `ReservaTenant::ordenPorCobrar()`: la orden de la
  cita si sigue pendiente; si no, la del saldo. Con ella:
  - `estado_pago` (`EstadoPagoCita`) sigue en `por_cobrar` mientras falte el saldo;
  - la agenda (`por_cobrar`, `por_cobrar_minor`) y el resumen del día cuentan la cita
    como por cobrar.
  - `total_minor` en la agenda es lo que cuesta la cita (las dos órdenes) y
    `anticipo_minor`, lo ya pagado al apartar.
  - El límite de citas por pagar (ADR 0065) no cuenta una cita con el anticipo pagado.
- **Cancelar**:
  - Vence sin pagar el anticipo, o se cancela la cita: se cancelan las dos órdenes que
    sigan pendientes. Si el anticipo llega tarde y la cita se reconfirma, el saldo
    vuelve a quedar por cobrar.
  - Cancela el cliente con el anticipo pagado, a tiempo o con una política que no
    penaliza cancelar tarde: la vista previa dice «El anticipo pagado en línea se
    devolverá automáticamente.». `DevolverAnticipoAlCancelarATiempo` lo devuelve por
    la misma pasarela. Es idempotente, con la llave `cancelacion-a-tiempo:<reserva>`,
    y solo devuelve pagos en línea: lo cobrado en caja se devuelve a mano.
  - Cancela el cliente tarde: la vista previa lo dice («el anticipo no se devuelve»),
    la reserva queda con motivo `tardia` y el aviso al cliente explica por qué.
  - Pasado el límite, `cancelacion.cliente_cancela_tarde` decide si el cliente aún
    puede cancelar él mismo, como con los créditos.
  - Cancela el negocio: aplica su regla de siempre
    (`cancelacion.devolver_pago_si_cancela_negocio`, ADR 0046).
  - Si no llega, el negocio conserva el anticipo.

## Consecuencias

- La página pública y la cuenta del cliente ofrecen «Pagar el anticipo» (por omisión)
  o «Pagar todo ahora», con lo que queda para el negocio. La confirmación y el enlace
  del correo dicen lo de ahora y lo del negocio.
- En el detalle de la cita el equipo ve «Anticipo pagado: $X · falta $Y», y «Registrar
  pago» cobra solo lo que falta.
- El catálogo de citas tiene el campo «Anticipo para apartar en línea», con el
  resultado en pesos a la vista.
- Facturar el anticipo y el resto se resuelve en el ADR 0163 (CFDI por remanente).
- La app móvil ofrece la misma elección (anticipo o todo) y muestra el anticipo pagado
  y lo que falta mientras falte.
