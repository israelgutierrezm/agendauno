# ADR 0075 — Cambiar el WhatsApp del negocio desde el panel

Estado: Aceptado (2026-09-30).

## Contexto

El WhatsApp del negocio se captura al registrarse. Es el de los avisos de AgendaUno
(ADR 0070–0072) y el del botón «Escríbenos por WhatsApp» de su página. Con el ADR
0072, el dueño podía verificarlo desde su panel, pero no cambiarlo: un número mal
escrito o que ya no usa solo se corregía escribiendo a soporte.

## Decisión

- **Dónde:** en «Renta» → «Avisos de AgendaUno», junto al número, «Cambiar» abre
  la lada y el número nuevo.
  - Lo ve quien tiene `estudio.gestionar`, porque el número también es el de la
    página del negocio. Los avisos siguen pidiendo `facturacion.ver`.
  - Sin WhatsApp con los dueños en la plataforma, la tarjeta igual muestra el número
    para quien puede cambiarlo, con «Es el que aparece en tu página».
- **Con WhatsApp con los dueños, el número cambia solo al confirmarlo:**
  - `POST /avisos-plataforma/whatsapp/cambio/codigo` manda el código al número
    nuevo, con el servicio del registro y sus topes (espera entre envíos, tope por
    número y tope diario).
  - `PUT /avisos-plataforma/whatsapp` con el número y el código lo cambia. Queda
    verificado y acepta los avisos, igual que al verificar.
  - Si el código no es correcto, el número anterior sigue como estaba.
- **Sin WhatsApp con los dueños:** `PUT /avisos-plataforma/whatsapp` guarda el número
  sin código y queda sin verificar. Si la plataforma lo enciende después, el dueño
  lo verifica con «Verificar por WhatsApp».
- **Reglas:**
  - Mismas validaciones del registro (lada de 1 a 4 dígitos, número de 7 a 15).
  - Si es el mismo número y ya está verificado, o no hay nada que verificar, se
    rechaza con «Ese ya es el WhatsApp de tu negocio».
  - Cada cambio queda en la bitácora del negocio (`negocio.whatsapp_cambiado`, con
    el número anterior y el nuevo).
  - Topes por IP en cada ruta: 5 códigos y 20 intentos de cambio cada 10 minutos.
  - Las dos rutas siguen abiertas con el negocio suspendido por renta (ADR 0073),
    para que el dueño reciba los avisos de pago en el número correcto.
- Las ladas frecuentes pasan a `src/lib/ladas.ts`, compartidas con el registro.

## Consecuencias

- El dueño corrige su número sin soporte, y con WhatsApp encendido nunca queda un
  número sin confirmar recibiendo avisos que cuestan.
- Cambiarlo también cambia el botón de WhatsApp de la página del negocio. Las
  sucursales conservan su propio WhatsApp.
