import { describe, expect, it } from "vitest";

import { render, rutasMarketing } from "./entry-marketing";

/*
| Las páginas comerciales se prerenderizan con su HTML completo (SEO). Si un
| componente de la barra pública falla en el servidor, la página sale vacía.
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

  it("prerenderiza las once páginas con un solo h1 y el menú comercial", async () => {
    expect(rutasMarketing).toHaveLength(11);
    for (const path of rutasMarketing) {
      const pagina = documento((await render(path)).html);
      expect(pagina.querySelectorAll("h1"), path).toHaveLength(1);
      // Comercial por su ruta (meta.marketing): menú y «Probar gratis», sin «Inicio».
      expect(
        pagina.querySelector('.tu-public-sections a[href="/clases"]'),
        path,
      ).not.toBeNull();
      expect(
        pagina.querySelector('.tu-public-sections a[href="/citas"]'),
      ).not.toBeNull();
      expect(pagina.querySelector(".tu-public-register"), path).not.toBeNull();
      expect(pagina.querySelector(".tu-public-back"), path).toBeNull();
      // Clases · Citas · Precios, en la barra y en la segunda fila del móvil.
      const menus = pagina.querySelectorAll(".tu-public-sections");
      expect(menus).toHaveLength(2);
      for (const menu of menus) {
        expect(
          [...menu.querySelectorAll("a")].map((a) => a.textContent?.trim()),
        ).toEqual(["Clases", "Citas", "Precios"]);
      }
    }
  });

  it("/clases y /citas: su h1, «Precios» en la misma página y registro con su modo", async () => {
    for (const modo of ["clases", "citas"] as const) {
      const pagina = documento((await render(`/${modo}`)).html);
      expect(pagina.querySelectorAll("h1")).toHaveLength(1);
      expect(pagina.querySelector("h1")?.textContent?.trim()).not.toBe("");
      expect(
        pagina.querySelectorAll(
          `.tu-public-sections a[href="/${modo}#precios"]`,
        ),
      ).toHaveLength(2);
      expect(
        pagina.querySelector(".tu-public-register")?.getAttribute("href"),
      ).toBe(`/registro?modo=${modo}`);
      expect(
        pagina
          .querySelector(`.tu-public-sections a[href="/${modo}"]`)
          ?.getAttribute("aria-current"),
      ).toBe("page");
    }
    // Fuera de /clases y /citas, «Precios» lleva a la portada.
    const portada = documento((await render("/")).html);
    expect(
      portada.querySelector('.tu-public-sections a[href="/#precios"]'),
    ).not.toBeNull();
    expect(
      portada.querySelector(".tu-public-register")?.getAttribute("href"),
    ).toBe("/registro");
  });

  it("en las páginas por giro, «Probar gratis» del menú lleva su giro si es uno solo", async () => {
    for (const [path, destino] of [
      ["/software-para-pilates", "/registro?modo=clases&giro=pilates"],
      ["/software-para-spas", "/registro?modo=citas&giro=spa"],
      // Junta dos giros: solo la modalidad.
      ["/software-para-barberias", "/registro?modo=citas"],
    ] as const) {
      const pagina = documento((await render(path)).html);
      expect(
        pagina.querySelector(".tu-public-register")?.getAttribute("href"),
        path,
      ).toBe(destino);
    }
  });
});
