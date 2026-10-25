import { describe, expect, it } from "vitest";

import { render, rutasMarketing, PRODUCTO_COMERCIAL } from "@/entry-marketing";
import { conMarca, i18n, mensajesComerciales } from "@/i18n";

/*
| Las páginas comerciales se pintan solo con los textos que van con la aplicación
| (ADR 0210): los del panel llegan aparte y la landing no los descarga. Un texto del
| panel en una de ellas saldría como su clave en el HTML pre-generado.
*/
describe("textos de las páginas comerciales", () => {
  it("cada página comercial se pinta sin los textos del panel", async () => {
    i18n.global.setLocaleMessage(
      "es-MX",
      conMarca(mensajesComerciales, PRODUCTO_COMERCIAL),
    );
    // De verdad sin los del panel (las pruebas los cargan al empezar).
    expect(Object.keys(i18n.global.getLocaleMessage("es-MX"))).not.toContain(
      "operacion",
    );
    const faltantes = new Set<string>();
    i18n.global.setMissingHandler((_locale, clave) => {
      faltantes.add(clave);
    });
    for (const ruta of rutasMarketing) {
      await render(ruta);
    }
    expect([...faltantes]).toEqual([]);
  });
});
