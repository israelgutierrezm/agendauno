# ADR 0210 — Los textos del panel se cargan aparte de la landing

Estado: Aceptado (2026-10-25).

## Contexto

La landing de cada producto se pre-genera con su HTML completo y después se hidrata con
la misma aplicación de Vue (ADR 0108). Esa aplicación traía todos sus textos en el
paquete inicial: `es-MX.ts` y las 122 secciones del panel, el portal y el superadmin
(unos 350 KB de fuente), aunque una persona que solo ve la portada nunca los usa.

## Decisión

- `i18n/index.ts` arranca solo con los textos comerciales (`mensajesComerciales`):
  `es-MX.ts`, `validacion`, `apariencia` (su panel está montado en toda página),
  `demoAgendable`, `demosPlataforma` y `medicion`. El diálogo de `confirmar()`, también
  montado siempre, pide su título solo abierto.
- Las demás secciones viven en `i18n/panel.ts` y llegan con `cargarMensajesPanel()`
  (una sola vez, un archivo aparte): al cargarse, se suman a `mensajesBase` y se vuelven
  a aplicar la terminología del negocio y la marca del producto.
- El router la espera antes de cualquier ruta que no sea comercial
  (`meta.marketing !== true`): el panel, el portal, el acceso y el registro nunca se
  pintan sin sus textos.
- Si en una página comercial se pide un texto que no está (un componente que se abre
  después de cargar), el `missing` de vue-i18n pide los textos del panel y la página se
  vuelve a pintar al llegar.
- Las pruebas cargan todo al empezar (`src/pruebas.setup.ts`);
  `textosComerciales.spec.ts` pinta cada página comercial solo con los textos
  comerciales y falla si alguna pide uno del panel.

## Consecuencias

- La landing descarga menos JavaScript; la primera pantalla del panel hace una petición
  más (los textos del panel), que después queda en caché.
- Una sección nueva que use una página comercial va en `mensajesComerciales`; si se
  olvida, la guardia lo dice.
