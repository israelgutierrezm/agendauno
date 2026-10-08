import { afterEach, describe, expect, it } from "vitest";
import {
  seoParaRuta,
  paginasMarketing,
  rutasMarketing,
  renderSeoHead,
} from "./seoConfig";
import { MODALIDADES, perfilDeSolucion } from "./modalidades";
import { soluciones, rutaSolucion } from "./soluciones";
import { updateSeo } from "@/lib/seo";

afterEach(() => {
  document.head.innerHTML = "";
});
describe("SEO comercial", () => {
  it("anuncia la prueba de 30 días sin conservar la oferta anterior", () => {
    expect(seoParaRuta("/").description).toContain("30 días");
    for (const path of rutasMarketing) {
      expect(renderSeoHead(seoParaRuta(path))).not.toContain("14 días");
    }
  });
  it("tiene once páginas con título, descripción y canonical propios", () => {
    expect(rutasMarketing).toHaveLength(11);
    expect(rutasMarketing.slice(0, 3)).toEqual(["/", "/clases", "/citas"]);
    expect(
      new Set(rutasMarketing.map((path) => seoParaRuta(path).title)).size,
    ).toBe(11);
    for (const path of rutasMarketing) {
      const seo = seoParaRuta(path);
      expect(seo.index).toBe(true);
      expect(seo.description.length).toBeGreaterThan(70);
      const html = renderSeoHead(seo);
      expect(html).toContain(`href="https://agendauno.mx${path}"`);
      expect(html).toContain('type="application/ld+json"');
      expect(html).not.toContain("aggregateRating");
    }
  });
  it("excluye acceso, activación, directorio y operación de la indexación comercial", () => {
    for (const path of [
      "/registro",
      "/aviso-de-privacidad",
      "/entrar",
      "/activar/negocio?token=secreto",
      "/negocios",
      "/panel",
      "/miembros/123",
      "/splataformadm1n",
    ]) {
      const html = renderSeoHead(seoParaRuta(path));
      expect(html).toContain('content="noindex,follow"');
      expect(html).not.toContain('rel="canonical"');
      expect(html).not.toContain("application/ld+json");
      expect(html).not.toContain("secreto");
    }
  });
  it("limpia canonical y schema al navegar a una pantalla interna", () => {
    updateSeo(seoParaRuta("/software-para-pilates"));
    expect(
      document.querySelector('link[rel="canonical"]')?.getAttribute("href"),
    ).toContain("software-para-pilates");
    updateSeo(seoParaRuta("/panel"));
    expect(document.title).toBe("AgendaUno");
    expect(
      document.querySelector('meta[name="robots"]')?.getAttribute("content"),
    ).toBe("noindex,follow");
    expect(document.querySelector('link[rel="canonical"]')).toBeNull();
    expect(document.querySelector('meta[property="og:url"]')).toBeNull();
    expect(
      document.querySelector('script[type="application/ld+json"]'),
    ).toBeNull();
    updateSeo(seoParaRuta("/"));
    expect(
      document.querySelector('meta[name="robots"]')?.getAttribute("content"),
    ).toContain("index,follow,max-image");
  });
  it("escapa contenido HTML y scripts en los metadatos", () => {
    const html = renderSeoHead({
      title: '<script>"x"</script>',
      description: "A & B",
      index: false,
      jsonLd: { text: "</script>" },
    });
    expect(html).toContain("&lt;script&gt;");
    expect(html).toContain("A &amp; B");
    expect(html).toContain("\\u003c/script>");
  });
  it("solo /clases y /citas usan una dirección de un segmento; los negocios, su subdominio", () => {
    // Las páginas por giro conservan su prefijo; /clases y /citas son las únicas
    // comerciales de un segmento. El enlace corto de un negocio (`/{slug}`) en el
    // dominio principal lleva a su subdominio (lib/tenant) y no se indexa.
    expect(
      soluciones.every((s) =>
        rutaSolucion(s.slug).startsWith("/software-para-"),
      ),
    ).toBe(true);
    expect(
      rutasMarketing
        .filter(
          (path) =>
            /^\/[^/]+$/.test(path) && !path.startsWith("/software-para-"),
        )
        .sort(),
    ).toEqual(["/citas", "/clases"]);
    expect(seoParaRuta("/mi-estudio").index).toBe(false);
    expect(renderSeoHead(seoParaRuta("/estudio/mi-estudio"))).toContain(
      'content="noindex,follow"',
    );
  });
  it("/clases y /citas: SEO propio, imagen existente y miga de pan", () => {
    for (const modo of ["clases", "citas"] as const) {
      const { seo } = MODALIDADES[modo];
      const resultado = seoParaRuta(`/${modo}/`);
      expect(resultado).toMatchObject({
        title: seo.title,
        description: seo.description,
        path: `/${modo}`,
        index: true,
        image: `https://agendauno.mx${seo.imagen}`,
      });
      expect(seo.imagen).toMatch(/^\/assets\/landing\//);
      const html = renderSeoHead(resultado);
      expect(html).toContain(
        `<link rel="canonical" href="https://agendauno.mx/${modo}">`,
      );
      expect(html).toContain(
        `<meta property="og:image" content="https://agendauno.mx${seo.imagen}">`,
      );
      expect(html).not.toContain("hreflang");
      const grafo = (resultado.jsonLd?.["@graph"] ?? []) as {
        "@type": string;
        itemListElement?: { name: string; item: string }[];
      }[];
      const tipos = grafo.map((nodo) => nodo["@type"]);
      expect(tipos).toContain("WebPage");
      expect(tipos).not.toContain("Offer");
      expect(tipos).not.toContain("FAQPage");
      expect(
        grafo.find((nodo) => nodo["@type"] === "BreadcrumbList")
          ?.itemListElement,
      ).toEqual([
        expect.objectContaining({
          name: "Inicio",
          item: "https://agendauno.mx/",
        }),
        expect.objectContaining({
          name: modo === "clases" ? "Clases" : "Citas",
          item: `https://agendauno.mx/${modo}`,
        }),
      ]);
    }
    expect(seoParaRuta("/citas").title).toBe(
      "Software de citas para barberías, estéticas y consultorios | AgendaUno",
    );
    // Lo que no existe no se anuncia en el buscador.
    for (const path of ["/clases", "/citas"]) {
      const { description } = seoParaRuta(path);
      expect(description).not.toMatch(/anticipo|WhatsApp|push/i);
    }
  });
  it("cada página por giro cuelga de su modalidad en la miga de pan (Inicio → Clases|Citas → giro)", () => {
    for (const s of soluciones) {
      const url = `https://agendauno.mx${rutaSolucion(s.slug)}`;
      const grafo = (seoParaRuta(rutaSolucion(s.slug)).jsonLd?.["@graph"] ??
        []) as Record<string, unknown>[];
      const pagina = grafo.find((n) => n["@type"] === "WebPage");
      expect(pagina?.breadcrumb).toEqual({ "@id": `${url}#breadcrumb` });
      const miga = grafo.find((n) => n["@type"] === "BreadcrumbList") as
        { itemListElement: Record<string, unknown>[] } | undefined;
      expect(miga?.itemListElement, s.slug).toEqual([
        {
          "@type": "ListItem",
          position: 1,
          name: "Inicio",
          item: "https://agendauno.mx/",
        },
        {
          "@type": "ListItem",
          position: 2,
          name: s.modo === "clases" ? "Clases" : "Citas",
          item: `https://agendauno.mx/${s.modo}`,
        },
        { "@type": "ListItem", position: 3, name: s.nombre, item: url },
      ]);
    }
    // La portada no lleva miga de pan.
    const portada = (seoParaRuta("/").jsonLd?.["@graph"] ?? []) as Record<
      string,
      unknown
    >[];
    expect(portada.map((n) => n["@type"])).not.toContain("BreadcrumbList");
  });
  it("cada página comercial declara su nombre de ruta, su vista y su modalidad", () => {
    expect(paginasMarketing.map((p) => p.path)).toEqual(rutasMarketing);
    expect(new Set(paginasMarketing.map((p) => p.name)).size).toBe(11);
    expect(paginasMarketing.slice(0, 3)).toEqual([
      { path: "/", name: "inicio", vista: "landing", modo: null },
      {
        path: "/clases",
        name: "modalidad-clases",
        vista: "modalidad",
        modo: "clases",
      },
      {
        path: "/citas",
        name: "modalidad-citas",
        vista: "modalidad",
        modo: "citas",
      },
    ]);
    for (const s of soluciones) {
      expect(
        paginasMarketing.find((p) => p.path === rutaSolucion(s.slug)),
      ).toEqual({
        path: rutaSolucion(s.slug),
        name: `solucion-${s.slug}`,
        vista: "solucion",
        modo: s.modo,
        slug: s.slug,
        giro: perfilDeSolucion(s.slug),
      });
    }
  });
});
