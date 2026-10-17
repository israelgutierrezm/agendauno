# ADR 0152 — Aviso de la renta en el panel

Estado: Aceptado (2026-10-16).

## Contexto

Cuando el dueño se atrasa en el pago de la suscripción, ya hay varios avisos (ADR 0071 y
0073), todos por correo y, si lo aceptó, por WhatsApp:

- la renta lista para pagar;
- la renta vencida;
- la suspensión próxima, 3 días antes (`renta.dias_aviso_suspension`);
- el negocio suspendido;
- el pago recibido.

Dentro del panel, en cambio, no se veía nada hasta que el negocio ya estaba suspendido.
Solo entonces aparecía el aviso, en «Mi suscripción».

Un dueño que no lee esos correos, o a quien le caen en spam, se entera de la deuda
cuando su negocio ya está cerrado. Para entonces sus clientes ya no pueden agendar.

## Decisión

- **`GET /renta/aviso`** (con `facturacion.ver`) devuelve el pago pendiente más antiguo
  que venza (las compras de timbres no vencen) y qué tan urgente es. Lo calcula
  `AvisoRentaTenant`. Los niveles, del más urgente al más leve:
  - `por_suspender`: ya venció y está dentro de los días de aviso antes de la suspensión.
  - `vencido`: pasó la fecha para pagar; dice cuántos días faltan para suspender.
  - `rechazado`: aún no vence, pero el banco rechazó el cobro a la tarjeta domiciliada.
  - `por_vencer`: vence en los próximos días y no hay tarjeta que lo pague sola. Son 3
    por omisión, con el parámetro de plataforma `renta.dias_recordatorio_pago` (0 = solo
    se avisa ya vencido).
  - Sin nada de eso, `null`. Con tarjeta y sin rechazo, no se recuerda: se cobra solo.
- **En la web**, `AvisoRenta` va arriba de cada pantalla del panel:
  - con el tono de su urgencia: información, aviso o peligro;
  - con el monto y las fechas, y el botón «Pagar ahora», que lleva a «Mi suscripción».
  - No se repite en «Mi suscripción», donde ya está el detalle para pagar. Se vuelve a
    consultar al salir de ahí, por si pagó.
- **Solo para quien ve la facturación.** Al resto del equipo no se le muestra la deuda.
  Si el negocio llega a suspenderse, ven el aviso neutro de siempre (ADR 0073).
- El «hoy» es el mismo con que se suspende (`SuspensionPorRenta`), para que las fechas del
  aviso y la suspensión coincidan.

## Consecuencias

- El dueño ve el atraso en cada pantalla mientras trabaja, con días de anticipación, y
  no solo por correo.
- Los días del recordatorio, de aviso y de gracia son parámetros de la plataforma: el
  aviso del panel los respeta sin números fijos.
- La app móvil no muestra este aviso: el dueño administra desde la web.
