import { describe, expect, it } from "vitest";

import { render } from "./entry-marketing";

/*
| Las páginas comerciales se prerenderizan con su HTML completo (SEO). Si un
| componente de la barra pública falla en el servidor, la página sale vacía.
*/

describe("prerender de marketing", () => {
  it("la portada sale con su contenido y la opción de entrar", async () => {
    const { html } = await render("/");
    expect(html.length).toBeGreaterThan(2000);
    expect(html).toContain('href="/entrar"');
  });
});
