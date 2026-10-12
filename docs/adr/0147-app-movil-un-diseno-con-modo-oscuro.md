# ADR 0147 — La app móvil: un solo diseño, en claro y en oscuro

Estado: Aceptado (2026-10-12). Pedido: «está bien que la app tenga un tema fijo, solo
que hay que crear un modo noche para que se adapte al tema del móvil. Es decir, los
temas solo en web y en la app un diseño unificado».

## Contexto

- En la web cada usuario elige su tema (AgendaUno, Océano, Esmeralda, Medianoche…) y
  ajusta colores (`CatalogoTemas`).
- La app (Flutter) tenía un tema claro fijo, el AgendaUno de la web. Sus colores eran
  constantes (`TemaAgendaUno.textoSuave`…) y había blancos y grises escritos a mano en
  la Agenda, el pase de lista y la ficha de cita: con el teléfono en modo oscuro la app
  seguía en claro.

## Decisión

- Los temas a elegir son solo de la web. La app tiene un diseño, en claro y en oscuro,
  y el modo lo decide el teléfono (`themeMode: ThemeMode.system`). No se lee la
  apariencia del usuario.
- Los colores son una extensión del tema (`ColoresAgendaUno`, `ThemeExtension`) con dos
  juegos:
  - claro: los de siempre (los del tema AgendaUno de la web);
  - oscuro: azul marino (fondo `#0B1426`, superficies `#131F36` y `#1B2944`), letra
    `#EEF2F8` y la tenue `#A3B1C9`, el azul de la marca aclarado (`#5EA2FF`) con letra
    oscura encima (`#06132B`), y éxito, aviso y error más claros.
- Las pantallas los leen con `context.colores`: al cambiar el modo del teléfono se
  vuelven a pintar solas, sin reiniciar la app.
- Los colores pensados para fondo claro (estados de cita, tonos de servicio, avisos)
  pasan por `par()` y `tinta()`: en oscuro la tinta se aclara y el fondo es esa tinta,
  suave, sobre la superficie. Así no hay que definir cada color dos veces.
- Se quedan como están: el QR del pase (sobre blanco, para que se lea), el texto sobre
  fotos y el logo del calendario.

## Consecuencias

- En los dos modos la letra cumple AA (4.5:1) sobre fondos, tarjetas y botones; una
  prueba lo revisa (`test/tema_modo_oscuro_test.dart`).
- Un color nuevo en la app se toma de `context.colores`, no se escribe a mano.
- Si algún día la app debe seguir el tema de la web, la extensión ya separa los colores
  de las pantallas: bastaría con armarla desde la apariencia del usuario.
