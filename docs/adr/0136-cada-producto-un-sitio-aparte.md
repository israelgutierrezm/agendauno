# ADR 0136 — Para quien entra, cada producto es un sitio aparte

Estado: Aceptado (2026-10-11). Pedido del dueño: «En los negocios recientes solo debe
encontrar los de AgendaUno o TurnoUno; para el cliente no debería ser notorio que
ocupan una base. Deben ser negocios independientes».

## Contexto

AgendaUno y TurnoUno comparten la API y la base de la plataforma (ADR 0108). En
producción cada uno tiene su dominio, y la API ya no abre un negocio fuera del suyo.
Pero en la web:

- «Tus negocios recientes» guardaba una sola lista por navegador. En desarrollo, o
  donde los dos productos compartan origen, la entrada de TurnoUno mostraba negocios
  de AgendaUno.
- La búsqueda de negocios de «Entrar» no decía de qué producto buscaba. En
  desarrollo, fuera de los dominios, salían los de los dos.
- La dirección de un negocio del otro producto, escrita a mano o en un enlace, lo
  abría como si fuera de este.

## Decisión

- **Recientes por producto:** cada producto guarda su propia lista
  (`{producto}.negocios-recientes.v2`). Un negocio se guarda en la lista de su producto
  (lo dicen `/marca`, el directorio y la página del negocio). La lista anterior (v1)
  no decía el producto y ya no se lee.
- **Búsqueda:** «Entrar» pide al directorio solo los negocios de su producto
  (`producto`), igual que la página del directorio.
- **Un negocio del otro producto** no se abre ni se recuerda. Se dice «{negocio} usa
  {producto}» con el enlace para entrar desde allá: su dominio en producción y, en
  desarrollo, la misma página con `?producto=`.
- **Collage de «Entrar»:** una foto arriba y dos abajo, en pirámide, en el mismo alto.

## Consecuencias

- En ninguna pantalla de entrada se ve que los dos productos comparten plataforma.
- Quien ya tenía recientes los vuelve a ver al entrar de nuevo a su negocio, una vez.
