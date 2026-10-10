import { afterEach, describe, expect, it, vi } from "vitest";
import {
  seoParaRuta,
  paginasMarketing,
  rutasMarketing,
  renderSeoHead,
} from "./seoConfig";
import { MODALIDADES, perfilDeSolucion } from "./modalidades";
import { soluciones, rutaSolucion } from "./soluciones";
import { updateSeo } from "@/lib/seo";

/*
| SEO de las landings (ADR 0108): cada dominio publica las páginas de su producto. Sin
| `VITE_PRODUCTO` (y en localhost) rige AgendaUno: clases en agendauno.mx. TurnoUno
| (citas, turnouno.mx) se prueba con su build.
*/

afterEach(() => {
  document.head.innerHTML = "";
  vi.unstubAllEnvs();
  vi.resetModules();
});

const deClases = soluciones.filter((s) => s.modo === "clases");
const deCitas = soluciones.filter((s) => s.modo === "citas");

describe("SEO comercial de AgendaUno", () => {
  it("anuncia la prueba de 30 días sin conservar la oferta anterior", () => {
    expect(seoParaRuta("/").description).toContain("30 días");
    for (const path of rutasMarketing) {
      expect(renderSeoHead(seoParaRuta(path))).not.toContain("14 días");
    }
  });
  it("la portada y las páginas por giro de clases, con título, descripción y canonical propios", () => {
    expect(rutasMarketing).toEqual([
      "/",
      ...deClases.map((s) => rutaSolucion(s.slug)),
    ]);
    expect(
      new Set(rutasMarketing.map((path) => seoParaRuta(path).title)).size,
    ).toBe(rutasMarketing.length);
    for (const path of rutasMarketing) {
      const seo = seoParaRuta(path);
      expect(seo.index).toBe(true);
      expect(seo.description.length).toBeGreaterThan(70);
      const html = renderSeoHead(seo);
      expect(html).toContain(`href="https://agendauno.mx${path}"`);
      expect(html).toContain('type="application/ld+json"');
      expect(html).toContain('og:site_name" content="AgendaUno"');
      expect(html).not.toContain("aggregateRating");
      expect(html).not.toContain("TurnoUno");
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
      "/clases",
      "/citas",
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
  it("solo la portada es comercial de un segmento; los negocios, su subdominio", () => {
    // Las páginas por giro conservan su prefijo. El enlace corto de un negocio
    // (`/{slug}`) en el dominio principal lleva a su subdominio (lib/tenant).
    expect(
      soluciones.every((s) =>
        rutaSolucion(s.slug).startsWith("/software-para-"),
      ),
    ).toBe(true);
    expect(
      rutasMarketing.filter(
        (path) => /^\/[^/]+$/.test(path) && !path.startsWith("/software-para-"),
      ),
    ).toEqual([]);
    expect(seoParaRuta("/mi-estudio").index).toBe(false);
    expect(renderSeoHead(seoParaRuta("/estudio/mi-estudio"))).toContain(
      'content="noindex,follow"',
    );
  });
  it("la portada: el SEO de clases, imagen existente y sin miga de pan", () => {
    const { seo } = MODALIDADES.clases;
    const resultado = seoParaRuta("/");
    expect(resultado).toMatchObject({
      title: seo.title,
      description: seo.description,
      path: "/",
      index: true,
      image: `https://agendauno.mx${seo.imagen}`,
    });
    expect(seo.imagen).toMatch(/^\/assets\/landing\//);
    const html = renderSeoHead(resultado);
    expect(html).toContain(
      '<link rel="canonical" href="https://agendauno.mx/">',
    );
    expect(html).not.toContain("hreflang");
    const tipos = (
      (resultado.jsonLd?.["@graph"] ?? []) as { "@type": string }[]
    ).map((nodo) => nodo["@type"]);
    expect(tipos).toContain("WebPage");
    expect(tipos).not.toContain("Offer");
    expect(tipos).not.toContain("FAQPage");
    expect(tipos).not.toContain("BreadcrumbList");
    // Lo que no existe no se anuncia en el buscador.
    expect(resultado.description).not.toMatch(/anticipo|WhatsApp|push/i);
  });
  it("cada página por giro cuelga de la portada en la miga de pan (Inicio → giro)", () => {
    for (const s of deClases) {
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
        { "@type": "ListItem", position: 2, name: s.nombre, item: url },
      ]);
    }
    // Una página de citas no existe en agendauno.mx.
    expect(seoParaRuta(rutaSolucion(deCitas[0]!.slug)).index).toBe(false);
  });
  it("cada página comercial declara su nombre de ruta, su vista y su modalidad", () => {
    expect(paginasMarketing.map((p) => p.path)).toEqual(rutasMarketing);
    expect(paginasMarketing[0]).toEqual({
      path: "/",
      name: "inicio",
      vista: "modalidad",
      modo: "clases",
    });
    for (const s of deClases) {
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

describe("SEO comercial de TurnoUno", () => {
  it("su build publica la portada y las páginas de citas en turnouno.mx, con su marca", async () => {
    vi.stubEnv("VITE_PRODUCTO", "turnouno");
    vi.resetModules();
    const seo = await import("./seoConfig");

    expect(seo.SITE_URL).toBe("https://turnouno.mx");
    expect(seo.rutasMarketing).toEqual([
      "/",
      ...deCitas.map((s) => rutaSolucion(s.slug)),
    ]);
    expect(seo.paginasMarketing[0]).toMatchObject({ path: "/", modo: "citas" });
    for (const path of seo.rutasMarketing) {
      const html = seo.renderSeoHead(seo.seoParaRuta(path));
      expect(html).toContain(`href="https://turnouno.mx${path}"`);
      expect(html).toContain('og:site_name" content="TurnoUno"');
      expect(html).not.toContain("AgendaUno");
      expect(html).not.toContain("agendauno.mx");
    }
    expect(seo.seoParaRuta("/").title).toBe(
      "Software de citas para barberías, estéticas y consultorios | TurnoUno",
    );
  });
});
