# ADR 0215 — Perfiles de operador del superadmin

Estado: Aceptado (2026-10-26). Completa el ADR 0132 (equipo y bitácora del superadmin).

## Contexto

Todos los operadores del superadmin podían hacer todo: quien solo atiende soporte podía
cambiar las pasarelas de la plataforma, publicar tarifas o dar de baja un negocio. El
dueño decidió tener perfiles de operador en la V1.

## Decisión

- Cada operador tiene un perfil (`administradores_plataforma.perfil`): `total`,
  `cobranza`, `soporte` o `lectura`. Los que ya existían quedan con `total`.
- Consultar no cambia nada: todos ven todo. Lo que cambia algo depende del perfil
  (`PerfilesPlataforma`), revisado en el servidor en cada petición (no basta con
  esconder botones):
  - `total`: todo, incluido el equipo, la configuración, las pasarelas, las tarifas,
    los parámetros, los legales, la medición, la modalidad y el país de un negocio y
    su baja.
  - `cobranza`: la renta: cobros, cargos, pagos a mano, facturas, condonar, suspender
    y reactivar, lo pactado de cada negocio y el tipo de cambio.
  - `soporte`: ayudar a los negocios: su prueba, su terminología, sus módulos, su
    WhatsApp, las demos, los errores y Wellhub.
  - `lectura`: solo ver.
- Una ruta nueva que cambia algo y no está en ningún perfil solo la usa `total`: lo
  nuevo nace restringido.
- Siempre queda al menos un operador `total` activo, y nadie se cambia su propio
  perfil.
- El token de la plataforma usado directamente (fuera de producción, scripts y
  pruebas) cuenta como `total`.
- La web esconde a quien no es `total` las secciones de configuración (tarifas,
  parámetros, configuración, medición y equipo); el resto lo rechaza el servidor con
  un mensaje claro.
