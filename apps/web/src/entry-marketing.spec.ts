import { describe, expect, it } from "vitest";

import { render, rutasMarketing } from "./entry-marketing";

/*
| Las páginas comerciales se prerenderizan con su HTML completo (SEO). Si un
| componente de la barra pública falla en el servidor, la página sale vacía. Sin
| `VITE_PRODUCTO` rige AgendaUno (ADR 0108): su portada de clases y sus páginas por
| giro; la landing de TurnoUno la revisan `scripts/check-marketing*.mjs` con su build.
*/

function documento(html: string): Document {
  return new DOMParser().parseFromString(html, "text/html");
}

describe("prerender de marketing", () => {
  it("la portada sale con su contenido y la opción de entrar", async () => {
    const { html } = await render("/");
    expect(html.length).toBeGreaterThan(2000);
    expect(html).toContain('href="/entrar"');
  });

  it("prerenderiza la portada y las páginas por giro con un solo h1 y el menú del producto", async () => {
    expect(rutasMarketing[0]).toBe("/");
    expect(rutasMarketing.length).toBeGreaterThan(1);
    for (const path of rutasMarketing) {
      const pagina = documento((await render(path)).html);
      expect(pagina.querySelectorAll("h1"), path).toHaveLength(1);
      // Comercial por su ruta (meta.marketing): menú y «Probar gratis», sin «Inicio».
      expect(pagina.querySelector(".tu-public-register"), path).not.toBeNull();
      expect(pagina.querySelector(".tu-public-back"), path).toBeNull();
      // Funciones · Precios · Preguntas, en la barra y en la segunda fila del móvil.
      const menus = pagina.querySelectorAll(".tu-public-sections");
      expect(menus).toHaveLength(2);
      for (const menu of menus) {
        expect(
          [...menu.querySelectorAll("a")].map((a) => a.getAttribute("href")),
        ).toEqual(["/#soluciones", "/#precios", "/#preguntas"]);
      }
      // Sin la otra modalidad en el menú: es otro producto.
      expect(
        pagina.querySelector('.tu-public-sections a[href="/citas"]'),
      ).toBeNull();
    }
  });

  it("la portada: su h1 y el registro con su modo", async () => {
    const pagina = documento((await render("/")).html);
    expect(pagina.querySelector("h1")?.textContent?.trim()).not.toBe("");
    expect(
      pagina.querySelector(".tu-public-register")?.getAttribute("href"),
    ).toBe("/registro?modo=clases");
    expect(pagina.getElementById("precios")).not.toBeNull();
  });

  it("en las páginas por giro, «Probar gratis» del menú lleva su giro si es uno solo", async () => {
    for (const [path, destino] of [
      ["/software-para-pilates", "/registro?modo=clases&giro=pilates"],
      // Junta dos giros: solo la modalidad.
      ["/software-para-crossfit-hyrox", "/registro?modo=clases"],
    ] as const) {
      const pagina = documento((await render(path)).html);
      expect(
        pagina.querySelector(".tu-public-register")?.getAttribute("href"),
        path,
      ).toBe(destino);
    }
  });
});
