# ADR 0119 — Antes de reservar, la confirmación y el estado de la reserva

Estado: Aceptado (2026-10-10). Plan de mejoras de captación, M05 y C05 (bloque 4).

## Contexto

El alumno tocaba «Reservar» y la reserva quedaba hecha, sin ver qué plan usaba, cuánto
consumía ni hasta cuándo podía cancelar sin costo. Cancelar sí tenía vista previa calculada
en el servidor (fase 1, 1.4), pero decía «Se devolverá 1 crédito» aunque el plan ya hubiera
vencido. En el calendario del alumno, una reserva en lista de espera, con lugar ofrecido o
pendiente de pago se veía como «Reservada», y no se decía hasta cuándo aceptar un lugar
ofrecido. Después de reservar solo aparecía «Reservado.». Después de pagar no había un
siguiente paso.

## Decisión

**Antes de reservar.** `GET /mi/sesiones/{sesion}/antes-de-reservar` (solo clases; no aparta
nada). `AntesDeReservarTenant` responde con las mismas reglas que reservar: la ventana de
reserva, `ReservasTenant::evaluar()`, la cobertura del plan y la política de cancelación que
se congelaría en la reserva (R8). La respuesta:

- `resultado`: `reserva`, `lista_espera` (llena, con lista de espera; no consume) o
  `no_disponible`.
- Para `no_disponible`:
  - `codigo` es el estable del motor.
  - `motivo` es el principal: `sin_plan`, `clase`, `saldo`, `vigencia`, `sucursal`, `pausa`,
    `suspendido`, `ya_reservada`, `llena`, `fuera_de_ventana` o `no_reservable`.
  - `accion` es lo que le sirve: `ver_planes`, `revisar_vigencia`, `cambiar_sucursal`,
    `ver_reserva` u `otras_clases`. Sin acción, el camino es hablar con el negocio (pausa o
    suspensión).
- `plan`: nombre, ilimitado, vigencia y lo que le quedaría (`disponible_despues`).
- `unidades`: lo que consume.
- `de_pago`: la clase se paga al reservar.
- `cancelacion`: `limite`, `horas_limite`, `penaliza_tarde` y `cancela_tarde`.
- `sucursal` (con `mapa_url`), `requisitos` y `que_llevar` (ADR 0118).

Reservar vuelve a validar todo bajo el candado de la sesión. La vista previa es una lectura.

**La reserva dice lo mismo.** Las reservas del alumno suman:

- `plan`: el nombre del plan que usa;
- `unidades`;
- `cancelacion`: el límite con la política congelada en la reserva; `null` en lista de
  espera;
- `que_llevar`.

`/mi/perfil` devuelve la política de la plataforma cuando el negocio no configuró una, en
lugar de `null`.

**Cancelar no promete de más.** Si el crédito vuelve a un plan ya vencido,
`EfectoCancelacion` lo dice en el mensaje y en `plan_vencio`: el crédito no recupera
vigencia. La cancelación real usa la misma decisión.

**Web y app.**

- «Reservar» o «Lista de espera» abren el detalle con lo que va a pasar, y se confirma ahí.
- Reservada, la clase abre su detalle con la confirmación de lo que quedó, leída de la
  reserva real: plan, límite para cancelar, qué llevar, cómo llegar y agregar al calendario.
- En el calendario, cada reserva muestra su estado con palabras.
- Un lugar ofrecido dice hasta qué hora se acepta.
- Al volver de pagar, el siguiente paso es «Reserva una clase de tu plan», que abre las
  clases incluidas.
- La app Flutter muestra la misma vista previa en el detalle de la clase.

**Se mueve a la medición y el seguimiento (bloque 8):** el seguimiento interno de quien compró y
no reservó tras un plazo configurable, y la medición compra → primera reserva → primera
asistencia (C05).

## Consecuencias

- Una cancelación tardía no promete devolución, y un reintegro no promete que se pueda usar
  un crédito vencido.
- Lo que el alumno ve antes de confirmar sale del servidor. Si cambia algo entre ver y
  confirmar (otra persona tomó el último lugar), reservar responde con el error del motor y
  el detalle lo muestra.
- Reservar es un paso más (ver y confirmar). Es el costo de no reservar a ciegas.
