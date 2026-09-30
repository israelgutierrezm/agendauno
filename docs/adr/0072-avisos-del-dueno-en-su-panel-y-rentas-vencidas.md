# ADR 0072 — Avisos del dueño en su panel y rentas vencidas al superadministrador

Estado: Aceptado (2026-09-29).

## Contexto

Con los ADR 0070 y 0071 quedaron tres huecos:

- El dueño no podía dejar de recibir los WhatsApp de la plataforma; solo se
  apagaban para todos desde el superadministrador.
- El dueño que no verificó su WhatsApp al registrarse ya no podía hacerlo.
- El superadministrador no se enteraba de una renta vencida ni tenía un lugar para
  actuar sobre ella. La suspensión es manual, porque no hay suspensión automática.

## Decisión

- **Panel del dueño (página «Renta»):** una tarjeta «Avisos de AgendaUno» muestra a
  qué correo le llegan los avisos.
  - Si la plataforma tiene WhatsApp con los dueños, muestra su número, si está
    verificado y si también los quiere por WhatsApp.
  - Sin verificar, «Verificar por WhatsApp» manda el código al número del negocio
    (el mismo servicio del registro, con sus topes). Al confirmarlo queda
    verificado y acepta los avisos.
  - Verificado, puede dejar de recibirlos por WhatsApp o volver a recibirlos. El
    correo sigue llegando siempre.
  - API:
    - `GET/PUT /avisos-plataforma` (`acepta_whatsapp`);
    - `POST /avisos-plataforma/whatsapp/codigo`;
    - `POST /avisos-plataforma/whatsapp/verificar`.
  - Todas piden `facturacion.ver`, y cada cambio queda en la bitácora del negocio.
  - Se verifica el número del registro. Cambiarlo no está en el panel.
- **Correo del superadministrador, capturable:** Configuración → «Correo del
  superadministrador» (`correo_alertas` en `configuracion_plataforma`).
  - Tiene prioridad sobre `ALERTAS_CORREO`, que queda de respaldo.
  - Ahí llegan las alertas de la operación, y la verificación de producción lo
    acepta.
  - Guardar la configuración solo cambia lo que se manda; la llave de FacturAPI
    no se borra.
- **Renta vencida:** `agendauno:avisar-duenos` registra una alerta «Renta vencida»
  una vez por cargo, con el negocio, el periodo, el monto y la fecha. Llega por
  correo con el resumen de alertas.
- **Suspender a mano:** en Cobros, el filtro «Vencidos» (`vencidos=1`) lista las
  rentas por pagar ya vencidas, con el estado del negocio.
  - Cada fila tiene «Suspender», con confirmación y la renta vencida como motivo.
  - Si el negocio ya está suspendido, lo dice.

## Consecuencias

- El dueño controla su canal y el superadministrador decide, con la información a
  la mano, si suspende.
- La suspensión automática tras días de gracia llegó en el ADR 0073.
