# ADR 0086 — Confirmar lo delicado y corregir la forma de pago de un cobro en caja

Estado: Aceptado (2026-10-01). Anular un cobro queda pendiente de decisión (ver al
final).

## Contexto

Al revisar una cita, el negocio reportó:

- **Cobrar** en caja registraba el pago al primer clic.
- **Llegó / No asistió** se marcaba sin preguntar.
- Si alguien se equivocaba (cobró en efectivo y era transferencia, o marcó «no
  asistió» a quien sí llegó), el panel no ofrecía cómo corregirlo, aunque el backend
  ya corregía la asistencia y compensaba el crédito en el ledger («Corrección de
  asistencia», fase 1, punto 1.4).

Revisamos todas las escrituras del panel buscando acciones que mueven dinero,
créditos o puntos, o que no se deshacen, sin confirmación ni forma de corregirlas.

## Decisión

### Confirmar antes de lo delicado

`await confirmar(mensaje, { aceptar, peligro })` (lib/confirmar.ts), con un mensaje
que dice qué va a pasar (textos en `confirmaciones.es-MX.ts`):

- **Cobros:**
  - cobrar una cita;
  - vender desde Recepción y desde Ventas (producto, monto, forma y persona);
  - cobrar en el punto de venta;
  - pagar la renta.
- **CFDI:** timbrar una factura o la de un cargo de renta; un CFDI no se edita.
- **Créditos y puntos:**
  - agregar créditos;
  - pausar una membresía;
  - canjear, entregar o cancelar un canje;
  - ajustar puntos;
  - registrar el pago de un adeudo.
- **Lista de espera:** ofrecer lugares (se avisa a la fila).
- **Lo que no se recupera:**
  - publicar un consentimiento;
  - quitar una imagen o el logo;
  - ocultar la página pública.
- **Asistencia** (`lib/confirmarAsistencia.ts`):
  - «No vino» y cualquier corrección se confirman.
  - «Llegó» por primera vez no pregunta en las listas de clase: se marca con la clase
    enfrente, una por persona, y se puede corregir.
  - En la cita, que es una sola, se confirman las dos.

Ya tenían un paso de confirmación en la propia pantalla, y se quedan así:

- cancelar una reserva o una clase;
- reembolsar;
- transferir una reserva;
- enviar una difusión;
- rechazar una solicitud de privacidad;
- resolver una incidencia de cobro.

### Corregir después

- **La asistencia de una cita** ya marcada se corrige desde su detalle
  («Corregir: no asistió / sí llegó»), con el endpoint de siempre. El crédito se
  ajusta en el ledger.
- **La forma de pago de un cobro en caja** se corrige con
  `PUT /pagos/{pago}/metodo` (`ordenes.gestionar`, quien cobra en caja).
  `CorregirMetodoPagoTenant` cambia la forma del pago y de su orden; el monto no
  cambia. Lo permite solo si:
  - el negocio lo permite: `pagos.permitir_corregir_metodo` (sí por defecto);
  - está dentro del plazo: `pagos.horas_para_corregir`, 48 h por defecto, 0 = sin
    límite. Protege los cortes de días ya revisados;
  - es un cobro en caja (`proveedor = manual`), no un pago en línea;
  - está aprobado y sin devoluciones;
  - la venta no tiene una factura timbrada, porque el CFDI ya declaró su forma de
    pago.
- La corrección queda en la bitácora (`pago.metodo_corregido`) con la forma anterior,
  la nueva y el motivo.
- El corte de caja se calcula al consultarlo, así que refleja la forma corregida.
- La agenda manda en cada cita pagada su `pago` (`id`, `metodo`, `en_caja`,
  `corregible`), para ofrecer la corrección solo cuando procede. En la lista no se
  consulta la factura (sería una consulta por cita); eso se valida al guardar.
- Los dos parámetros aparecen en Configuración › Agenda y reservas › Límites y
  tiempos, grupo «Cobros en caja».

### Elegir a una persona escribiendo

Los selectores con todos los clientes (reservar, transferir, documentos, grupos,
lealtad, ventas) pasan a `BuscarPersona`: busca por nombre o correo, se maneja con el
teclado y muestra a la persona elegida con «Cambiar».

## Consecuencias

- Equivocarse al cobrar en caja con la forma de pago ya tiene arreglo sin tocar la
  base de datos, con rastro en la bitácora.
- Las ventas del punto de venta (tabla propia) no entran en esta corrección. Si se
  necesita, sería el mismo patrón sobre `ventas_pos`.

## Pendiente: anular un cobro hecho por error

Anular un cobro (decir que nunca se cobró) no es lo mismo que corregir su forma de
pago:

- La orden pagada es un estado final.
- Su cumplimiento pudo otorgar una membresía o créditos (quizá ya usados), sumar
  puntos de lealtad y comisiones, y facturarse.

Hoy lo más cercano es reembolsar, con reversa de créditos, que deja la orden pagada y
reembolsada. Reabrir la orden para volver a cobrarla es una decisión de dominio
aparte, que se tomará antes de construirla.
