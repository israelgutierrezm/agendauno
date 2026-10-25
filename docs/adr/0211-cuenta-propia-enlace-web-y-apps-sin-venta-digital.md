# ADR 0211 — Eliminar la cuenta propia, abrir la web desde la app y apps sin venta digital

Estado: Aceptado (2026-10-25).

## Contexto

La revisión para la V1 encontró tres huecos para publicar las apps en las tiendas:

- Apple y Google piden que cualquier persona pueda eliminar su cuenta desde la app (y
  Google Play, una página web que explique cómo). Solo el cliente podía pedir la baja de
  sus datos, y su cuenta seguía activa hasta que el negocio la atendía.
- Lo que se hace en la web (pago automático con OpenPay, conectar Google u Outlook) no
  se podía abrir desde la app sin volver a escribir la contraseña.
- Las apps vendían clases en línea (contenido digital) con cobro fuera de la tienda, lo
  que Apple (3.1.1) y Google suelen rechazar. El dueño decidió no venderlo en las apps.
- Al pagar desde la app, la pasarela regresaba a `/mi-cuenta/pagos`, que pide una sesión
  que el navegador no tiene.

## Decisión

- **Eliminar la cuenta propia** (`POST /yo/eliminar-cuenta`, con la contraseña): la
  cuenta deja de servir en el acto (sesiones cerradas y baja lógica del usuario). Si la
  persona además es cliente, queda pedida la baja de sus datos (ARCO), que el negocio
  atiende en el plazo de la ley. El único dueño no puede: primero da de baja el negocio.
  Quien entra solo con Google crea antes una contraseña. En la web, en Mi perfil; la
  página pública `/eliminar-cuenta` lo explica para la ficha de Google Play.
- **Abrir la web desde la app** (`POST /yo/enlace-web {destino}`): un código de un solo
  uso que vale 2 minutos (solo su hash, en la caché del negocio) y abre
  `/entrar?estudio=…&enlace=…`; la web lo canjea (`POST /enlace-web/canjear`) por una
  sesión con el rol de la app y va al destino, que solo puede ser una ruta de la web.
- **Regreso del pago a la app**: la app manda `volver_app` (su esquema: `agendauno`,
  `turnouno` o el de una marca blanca); la pasarela regresa a la página pública
  `/pago-app`, que confirma el pago con el servidor (`ref_pago`) y ofrece abrir
  `<esquema>://pagos?ref=…`. Un esquema que no es de una app se rechaza.
- **Sin venta digital en las apps**: la API marca la clase en línea (`clase.en_linea`,
  `en_linea` antes de reservar) y la orden de una clase en línea (`digital`); con
  `X-App-Producto`, cobrar una orden digital responde 422. Lo comprado se ve en la app;
  se compra en la web.

## Consecuencias

- Las apps cumplen lo de eliminar cuenta y la ficha de Play tiene su página.
- El enlace de un solo uso es una credencial de corta vida: no se guarda en claro y no
  sirve en otro negocio ni dos veces.
