# ADR 0120 — Lugares numerados de punta a punta

Estado: Aceptado (2026-10-10). Plan de mejoras de captación, B06 (bloque 5).

## Contexto

El motor ya aceptaba y validaba un lugar numerado (R4: `ofertas.lugares`, `reservas.lugar`,
validado bajo el candado de la sesión), y recepción lo elegía en el panel. El alumno no podía
elegirlo, ni ver el suyo, ni cambiarlo. Por ejemplo, quien llegaba desde la lista de espera
quedaba sin lugar. El plan pide no anunciar «elige tu reformer» solo porque el motor lo
soporte.

## Decisión

- **La clase decide.** Una clase numera lugares si su oferta tiene `lugares > 0`. Si no, el
  recorrido no cambia. Elegir es opcional: sin elección, el negocio asigna uno, como hasta
  ahora. No se dibuja un plano.
- **Ver los libres sin ver a nadie.** La vista previa de reservar (ADR 0119) y
  `GET /mi/sesiones/{sesion}/lugares` dan `total` y `ocupados` (solo números) y, en la
  segunda, `mio`.
- **Elegir al reservar.** `POST /mi/reservas` acepta `lugar`. El motor lo valida bajo el
  candado de la sesión.
- **Elegir o cambiar después.** `PUT /mi/reservas/{reserva}/lugar`
  (`ReservasTenant::cambiarLugar`) funciona solo en una reserva confirmada o por pagar y
  antes de que empiece la clase. Bloquea la sesión y la reserva y deja
  `reserva.lugar_cambiado` en la bitácora.
- **Nunca un cambio silencioso.** Un lugar tomado o fuera del catálogo responde
  `SPOT_UNAVAILABLE`. La pantalla lo dice y vuelve a cargar los libres.
- **Quién ocupa un lugar.** Lo ocupan las mismas reservas que ocupan cupo (ADR 0116:
  confirmada, ofrecida y por pagar). La lista de espera no aparta lugar.
- **Lo que no cambia.** Transferir conserva el lugar; reprogramar lo suelta (como antes);
  cancelar lo libera.
- **Accesible.** Un botón por número, con su estado dicho con texto («Lugar 3, ocupado») y
  con trama y tachado, no solo con color. Se usa con teclado.
- **Web y app.** La web elige al reservar y cambia después. La app elige al reservar y
  muestra el lugar en la reserva.

## Consecuencias

- Dos personas no se quedan con el mismo lugar: la segunda recibe `SPOT_UNAVAILABLE`.
- Una clase sin lugares numerados no muestra nada nuevo.
- Cambiar el lugar desde la app queda para después: hoy se hace en la web o en recepción.
