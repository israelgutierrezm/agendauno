# ADR 0103 — País y lada del negocio

Estado: Aceptado (2026-10-07). Precisa el ADR 0099 y reemplaza en parte los ADR 0061,
0067 y 0069 en lo de «sin lada se asume México».

## Contexto

La revisión de lo que ata AgendaUno a México encontró dos fallas que mandan datos a
quien no debe o los pierden:

- El país del negocio no se pedía al registrarse ni se podía cambiar, y un negocio sin
  país contaba como mexicano (`RegionNegocioTenant::enMexico`).
- Un celular capturado sin «+» se tomaba como mexicano: la lada era una sola para toda
  la plataforma (`agendauno.whatsapp.lada`). En Colombia o Estados Unidos (10 dígitos)
  el recordatorio, con nombre, negocio y horario, salía a un número de México; en
  Chile o España (9 dígitos) el número se descartaba. El enlace `wa.me` de la sucursal
  también anteponía 52 a un número de 10 dígitos.

## Decisión

- **Todo negocio tiene país** (`estudios.pais`, ISO 3166-1 alfa-2 en mayúsculas):
  - El registro lo pide (`POST /registro`, `pais` obligatorio y del catálogo); la zona
    horaria se sigue aceptando como antes. Sin lada del WhatsApp del dueño, la de su
    país.
  - Se cambia en «País, moneda y zona horaria» (`GET/PUT /negocio/region`, campo
    `pais`; la respuesta trae `pais` y `lada`). Queda en la bitácora
    (`negocio.pais`), como la moneda y la zona. País, moneda y zona se revisan juntos
    antes de aplicar: si uno no se puede (p. ej. la moneda, porque ya hay cobros), no
    cambia ninguno.
  - Al registrarse, la web propone la zona del navegador solo si es del país elegido;
    si no, la primera del país (la principal o la frecuente: la del centro de México,
    Nueva York, Madrid, Sídney…), nunca la de otro país.
  - Los negocios que ya existían quedan en México (`MX`, todos lo son) y la columna ya
    no admite vacío (`MX` por omisión). Ya no hay «sin país = México»: la facturación
    a clientes (ADR 0099) es para `pais` MX.
- **Catálogo de países** (`CatalogoPaises`): los 249 códigos de ISO 3166-1 más Kosovo
  (XK), cada uno con su lada de la UIT (solo dígitos: `52`, `57`, `1`). Los territorios
  sin lada propia usan la del país que les da servicio. No depende de la extensión
  intl; los nombres los pone la web (`Intl.DisplayNames`).
- **La lada del negocio** sale de su país (`RegionNegocioTenant::lada()`) y viaja en la
  sesión (`/yo` → `estudio.pais`, `estudio.lada`), en el escaparate y en las opciones
  para agendar una cita (`estudio.pais`, `estudio.lada`). Si la página de citas no la
  conoce, manda el celular sin lada (no supone México) y el API le pone la del negocio.
- **Celulares**: la web y la app mandan los capturados con lada como `+<lada> <número>`
  (`+57 3001234567`) y el API los guarda como llegan. Al usarlos
  (`TelefonoWhatsApp::normalizar($celular, $lada)`):
  - con «+» (o `00`), los dígitos tal cual; pero si la lada va aparte del número
    (`+<lada> <número>`, como lo arman la web y el registro), el número se limpia como
    uno nacional: sin el 0 de marcación nacional (`+593 0991234567` → `593991234567`)
    y sin la lada si la repite (`+52 52 55 1234 5678` → `525512345678`). Así se
    corrigen también los ya guardados;
  - sin «+», se antepone la lada **del negocio**, salvo que ya empiece con ella y sea
    más largo que un número nacional de su país (más de 10 dígitos; Brasil, China,
    Alemania, Argentina e Italia, 11; Indonesia, 12; Austria, 13). Un número nacional
    mide de 7 a 12 dígitos, sin el 0 de marcación nacional; en México, 10. En Italia,
    San Marino, Costa de Marfil y Congo el 0 inicial es del número y se conserva. El
    `521` de antes en México pasa a `52`;
  - el resultado mide de 8 a 15 dígitos, o no hay número.
  - Los avisos a clientes, «con celular» en la ficha y en «Mi privacidad», y el enlace
    `wa.me` de cada sucursal usan la lada del negocio. Los avisos al dueño usan la lada
    de su propio contacto (`contacto_whatsapp_pais`). La prueba del superadministrador
    sigue con la de la plataforma (`agendauno.whatsapp.lada`), que queda solo como
    último respaldo.
  - Al agendar sin cuenta (`POST /citas`) el celular también puede llegar con «+»; si
    no, se le pone la `lada` que se mande.
- Lo ya guardado no se migra: los negocios actuales son de México, su lada es 52 y nada
  cambia para ellos. Por eso el celular de una persona se compara como número, no como
  texto (`CelularesTenant`): al dar de alta, al editar, en «Mi perfil» y al buscar a
  alguien dado de baja con ese número, «55 1234 5678» y «+52 5512345678» son el mismo.
- Al teclear un número con «+» en el campo del celular, la lada se toma en cuanto se
  reconoce una seguida del número; mientras, lo escrito se queda como va.

## Consecuencias

- Un negocio de Bogotá o de Madrid avisa a sus clientes en su país aunque el número se
  haya capturado sin lada, y su botón de WhatsApp lleva a su número.
- Cambiar el país cambia la lada con que se completan los números sin «+» ya
  guardados: por eso la web y la app los mandan con la lada.
- Un número nacional que empieza con la lada y mide más que uno nacional de su país se
  toma como si ya la trajera. Con los largos por país de arriba, un celular de Brasil
  de la zona 55 ya no se confunde; en un país que no está en esa lista y cuyos números
  pasen de 10 dígitos podría pasar: se evita capturando con lada.
- La comparación del celular recorre las fichas que comparten sus últimos 7 dígitos y
  las normaliza al vuelo; si un día pesa, se guarda además el número normalizado en una
  columna con índice.
- Pendiente: país en la ficha del superadministrador, y textos y formatos que salgan
  del país (impuesto, idioma, documentos legales).
