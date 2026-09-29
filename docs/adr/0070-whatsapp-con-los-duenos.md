# ADR 0070 — WhatsApp con los dueños: verificar su número al registrarse

Estado: Aceptado (2026-09-29).

## Contexto

El ADR 0069 puso WhatsApp para que cada negocio avise a sus clientes, con un
interruptor del superadministrador. La plataforma también quiere hablar con los
dueños por WhatsApp. Lo primero es que, al registrar su negocio, el dueño pueda
confirmar que el número que dejó es suyo y aceptar avisos de AgendaUno. Son dos
usos distintos, con costos distintos, y cada uno debe poder encenderse por separado.

## Decisión

- **Una conexión y dos interruptores.** WhatsApp tiene una sola conexión con Meta:
  un número y un token. Encima de ella hay dos usos que el superadministrador
  enciende por separado:
  - **con los dueños** (`duenos`): la verificación al registrarse y los avisos de la
    plataforma;
  - **de los negocios a sus clientes** (`negocios`, ADR 0069).
  - `PUT /plataforma/whatsapp` recibe `{negocios, duenos, phone_number_id, token}`.
  - La configuración que se guardó antes con un solo interruptor (`encendido`) se
    lee como `negocios`.
  - Cada uso muestra las plantillas que hay que registrar en Meta.
- **En el registro, discreto y opcional.** En el paso «Contacto», debajo del
  WhatsApp, aparece la casilla «Recibir avisos de AgendaUno por WhatsApp», solo si
  el uso con los dueños está encendido (`GET /registro/whatsapp`).
  - Al marcarla se pide un código (`POST /registro/whatsapp/codigo`), que llega con
    la plantilla de **autenticación** `agendauno_codigo_verificacion`. Meta pone el
    texto, el botón «Copiar código» y la vigencia de 10 minutos.
  - Con los 6 dígitos se verifica solo (`POST /registro/whatsapp/verificar`) y
    devuelve un **comprobante**.
  - El registro manda el comprobante en `whatsapp_verificacion`.
  - Sin marcar la casilla, el registro sigue igual que antes. Si la marca, no puede
    continuar sin confirmar el código o desmarcarla.
- **Lo que queda guardado (control plane):**
  - `verificaciones_whatsapp` guarda cada código enviado, solo con su hash (HMAC
    con la llave de la app).
    - Tiene 5 intentos y vence en 10 minutos.
    - Ya confirmado, guarda el hash del comprobante. El comprobante vale 60
      minutos, es de un solo uso y solo sirve para ese número.
    - Un intento fallido cuenta aunque falle la respuesta.
  - En `estudios`:
    - `contacto_whatsapp_verificado_en`: cuándo confirmó su número;
    - `contacto_whatsapp_aceptado_en`: cuándo aceptó avisos (verificar implica
      aceptar).
  - El superadministrador ve «WhatsApp verificado» en la ficha del negocio.
- **Cada código cuesta, así que hay topes:**
  - reCAPTCHA (el mismo del registro);
  - límite por IP en cada ruta, con su propio contador;
  - 60 segundos entre envíos al mismo número;
  - tope por número por hora, parámetro de plataforma
    `whatsapp.codigos_por_numero_hora` (3 por omisión);
  - tope diario de toda la plataforma, `whatsapp.codigos_por_dia` (300 por
    omisión). Al llegar, avisa al superadministrador.
  - Si Meta rechaza el envío (token vencido, plantilla sin aprobar), el dueño ve un
    mensaje y puede seguir sin verificar. El superadministrador recibe la alerta
    «WhatsApp que no salió».

## Consecuencias

- La plataforma ya sabe qué dueños tienen un WhatsApp verificado y aceptaron
  avisos, sin frenar a quien no quiere.
- Todavía no hay avisos de la plataforma a los dueños (fin de la prueba, cobro de
  la renta, pago fallido). Cada uno necesita su plantilla de utilidad y se agrega
  aparte, solo para quien aceptó.
- Un dueño que no verificó al registrarse no puede hacerlo después desde su panel.
  Es trabajo aparte si hace falta.
- El número del registro sigue siendo del negocio (`estudios`). Si el dueño lo
  cambia, la verificación debería volver a pedirse; hoy el cambio no está en el
  panel.
