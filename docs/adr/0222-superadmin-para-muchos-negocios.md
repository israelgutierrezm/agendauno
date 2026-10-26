# ADR 0222 — El superadmin listo para muchos negocios

Estado: Aceptado (2026-10-26). Cierra los pendientes menores de la revisión del
superadmin para la V1.

## Contexto

La revisión del superadmin dejó puntos que no fallan con pocos negocios pero sí con
cientos, o con varios operadores: el alta creaba la base del negocio dentro de la
petición del registro (cientos de migraciones en MySQL) y, si fallaba, nadie podía
reanudarla; la pestaña Operación listaba en el disco de respaldos la carpeta de cada
negocio, una por una; el límite de peticiones contaba por IP (varios operadores en una
oficina se frenaban entre sí); extender la prueba tenía sus días fijos en el código; la
ficha no mostraba la domiciliación.

## Decisión

- **El alta en la cola**: en producción el registro responde sin esperar la base del
  negocio (`AprovisionarNegocioRegistrado`, único por negocio, con reintentos) y el correo
  de activación sale al terminar. Agotados los intentos queda la alerta «Negocio sin
  base», que pide atención, y la ficha ofrece «Reintentar crear su base»
  (`POST /plataforma/estudios/{e}/aprovisionar`). El aprovisionamiento ya era idempotente
  y reanudable. En desarrollo y pruebas sigue en línea (`agendauno.plataforma.aprovisionar_en_cola`,
  variable `APROVISIONAR_EN_COLA`).
- **Operación con un solo listado**: el último respaldo de cada negocio sale de un solo
  listado de la carpeta de respaldos (`RespaldosEstudio::ultimosPorNegocio`), no de uno
  por negocio.
- **Límite por sesión**: el del superadmin cuenta por sesión del operador (60 por minuto)
  y, más holgado, por IP (300), para quien prueba muchos tokens.
- **Extender la prueba**: el máximo de una vez es el parámetro de la plataforma
  `negocios.dias_max_extender_prueba` (90 por omisión); la ficha ofrece las opciones de
  siempre hasta ese máximo.
- **Domiciliación en la ficha**: si paga con tarjeta domiciliada y, en cada cargo
  pendiente, cuántas veces la rechazó la tarjeta y cuándo se reintenta.
- La verificación de producción avisa que sin Stripe tampoco se compran timbres ni
  paquetes de WhatsApp.

## Consecuencias

- En producción el registro ya no devuelve el estado `trialing`: devuelve
  `provisioning` hasta que la cola termina (la web ya pedía revisar el correo).
- La cola debe estar corriendo para que se den de alta negocios (ya lo vigila el latido).
