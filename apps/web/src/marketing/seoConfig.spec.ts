import { afterEach, describe, expect, it } from "vitest";
import { seoParaRuta, rutasMarketing, renderSeoHead } from "./seoConfig";
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
  it("tiene nueve páginas con título, descripción y canonical propios", () => {
    expect(rutasMarketing).toHaveLength(9);
    expect(
      new Set(rutasMarketing.map((path) => seoParaRuta(path).title)).size,
    ).toBe(9);
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
      "/plataforma",
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
  it("mantiene rutas explícitas sin interceptar los slugs de los negocios", () => {
    expect(
      soluciones.every((s) =>
        rutaSolucion(s.slug).startsWith("/software-para-"),
      ),
    ).toBe(true);
    expect(seoParaRuta("/mi-estudio").index).toBe(false);
  });
});
