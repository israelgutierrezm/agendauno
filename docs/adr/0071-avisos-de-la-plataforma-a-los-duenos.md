# ADR 0071 — Avisos de la plataforma a los dueños

Estado: Aceptado (2026-09-29).

## Contexto

La plataforma no le avisaba nada al dueño sobre su cuenta. La prueba gratis
terminaba sin avisar. La renta mes vencido (ADR 0019 y 0032) se emitía, vencía o se
pagaba sin que nadie se lo dijera; solo lo veía si entraba a «Renta». Con WhatsApp
con los dueños (ADR 0070), el dueño que lo aceptó al registrarse también puede
recibir estos avisos por ahí.

## Decisión

- **Cuatro avisos:**
  - `prueba_por_terminar`: unos días antes del fin de la prueba. Son 3 por
    omisión, con el parámetro de plataforma `duenos.dias_aviso_prueba`.
  - `renta_emitida`: la renta del mes ya está lista, con su monto y vencimiento.
  - `renta_vencida`: la renta venció y sigue sin pagarse.
  - `pago_recibido`: se recibió el pago de la renta.
  - Todos llevan un enlace que abre la renta en su panel:
    `/entrar?estudio={slug}&volver=/renta`.
- **Canales:**
  - **Correo, siempre**, al correo de contacto del negocio. Es el registro y no
    cuesta.
  - **WhatsApp además**, solo si el superadministrador tiene encendido «Con los
    dueños» y el dueño lo aceptó al registrarse (`contacto_whatsapp_aceptado_en`).
    Usa plantillas de Utilidad (`agendauno_prueba_por_terminar`,
    `agendauno_renta_emitida`, `agendauno_renta_vencida`,
    `agendauno_pago_recibido`) que la tarjeta de WhatsApp del superadministrador
    lista para registrarlas en Meta.
  - El texto es el mismo por los dos canales (`PlantillasWhatsApp::DUENOS`). El
    correo tiene además su asunto.
- **Nunca dos veces.** `avisos_duenos` (control plane) guarda cada aviso: negocio,
  tipo, referencia y canal, con índice único. La referencia es la fecha de fin de la
  prueba (si se extiende, hay aviso nuevo) o el cargo.
  - Solo se mira hacia atrás unos días para no mandar de golpe avisos viejos al
    estrenar esto: una renta emitida en los últimos 3 días, una vencida en los
    últimos 7, un pago en los últimos 2.
- **Envío:** `agendauno:avisar-duenos` corre cada hora de 09:00 a 20:00 de CDMX.
  Arma los que tocan y los manda.
  - Cada aviso tiene 3 intentos. Si se agotan, el superadministrador recibe la
    alerta «Correo que no salió» o «WhatsApp que no salió».
  - Un WhatsApp en cola se descarta si la plataforma apaga «Con los dueños».
- **Para soporte:** la ficha del negocio en el superadministrador muestra los
  últimos 10 avisos, con su canal, su fecha y su estado.

## Consecuencias

- El dueño se entera de su prueba y de su renta sin tener que entrar al panel.
- El dueño no puede dejar de recibir los WhatsApp desde su panel. Hoy se apaga
  para todos desde el superadministrador. Darle la opción, junto con verificar su
  número desde el panel, es trabajo aparte.
- No hay suspensión automática por renta vencida. El aviso de vencida no amenaza
  con suspender; solo pide ponerse al corriente.
