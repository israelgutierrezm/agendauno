import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import LandingView from "./LandingView.vue";
import CarruselNegocios from "@/components/CarruselNegocios.vue";

vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
afterEach(() => vi.unstubAllGlobals());

function montar() {
  vi.stubGlobal(
    "IntersectionObserver",
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  );
  return mount(LandingView, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
      stubs: {
        RouterLink: { template: "<a><slot /></a>" },
        CarruselNegocios: true,
        FuncionesLanding: true,
      },
    },
  });
}
describe("landing comercial", () => {
  it("ofrece 30 días de prueba en todas las secciones", () => {
    const vista = montar();
    expect(vista.get(".tu-hero-proof").text()).toContain("30 días");
    expect(vista.get(".tu-confianza").text()).toContain("30 días");
    expect(vista.get("#producto").text()).toContain("30 días");
    expect(vista.text()).not.toMatch(/14 días/);
    vista.unmount();
  });
  it("presenta los negocios animados sin sustituir el titular principal", () => {
    const vista = montar();
    expect(vista.get(".tu-hero .negocios-texto").text()).toContain(
      "estudios de Pilates",
    );
    expect(vista.get(".negocios-animados .sr-only").text()).toContain(
      "barberías",
    );
    expect(vista.findAll("h1")).toHaveLength(1);
    expect(vista.get("h1").text()).toBe(es.landing.titulo);
    vista.unmount();
  });
  it("presenta primero la demo y separa la operación en una banda propia", () => {
    const vista = montar();
    const texto = vista.text();
    expect(texto.indexOf(es.landing.producto.titulo)).toBeLessThan(
      texto.indexOf("Tu negocio tiene su ritmo."),
    );
    expect(vista.find("#soluciones .tu-operacion").exists()).toBe(false);
    expect(vista.get("#operacion .tu-operacion").exists()).toBe(true);
    vista.unmount();
  });
  it("alterna el fondo del hero y la superficie entre secciones", () => {
    const vista = montar();
    const bandas = vista.findAll("section.tu-banda");
    expect(bandas).toHaveLength(11);
    bandas.forEach((banda, indice) => {
      const fondo = indice % 2 === 0 ? "var(--fondo)" : "var(--superficie)";
      expect(banda.attributes("style")).toContain(`background: ${fondo}`);
    });
    vista.unmount();
  });
  it("destaca beneficios sin cambiar los títulos ni su jerarquía SEO", () => {
    const vista = montar();
    expect(vista.findAll("h1")).toHaveLength(1);
    const titulos = vista.findAll("h2");
    for (const texto of [
      es.landing.producto.titulo,
      es.landing.comoFunciona.titulo,
      es.landing.seccionTitulo,
      es.landing.operacion.titulo,
      es.landing.precio.titulo,
      es.landing.comunidad.titulo,
      es.landing.paraQuien.titulo,
      es.landing.ctaFinalTitulo,
    ]) {
      const titulo = titulos.find((n) => n.text() === texto);
      expect(titulo, texto).toBeDefined();
      expect(titulo!.findAll(".tu-titulo-enfasis")).toHaveLength(
        [es.landing.seccionTitulo, es.landing.producto.titulo].includes(texto)
          ? 1
          : 0,
      );
    }
    expect(
      titulos
        .find((n) => n.text() === es.landing.seccionTitulo)!
        .get(".tu-titulo-enfasis")
        .text(),
    ).toBe("crecer");
    expect(vista.findAll(".tu-titulo-enfasis").map((n) => n.text())).toEqual([
      "Más",
      "agenda visual",
      "crecer",
    ]);
    expect(vista.get("#producto .tu-titulo-enfasis").classes()).toContain(
      "tu-titulo-enfasis--rosa",
    );
    expect(vista.get("#producto .tu-titulo-enfasis").classes()).toContain(
      "tu-titulo-enfasis--negrita",
    );
    expect(vista.get("#soluciones .tu-titulo-enfasis").classes()).toContain(
      "tu-titulo-enfasis--negrita",
    );
    expect(vista.findAll(".tu-enfasis-rosa").map((n) => n.text())).toEqual([
      "ritmo",
      "agenda",
    ]);
    expect(vista.get("#como-funciona h2").text()).toBe(
      "Empieza en solo tres pasos",
    );
    vista.unmount();
  });
  it("conserva la frase de marca y presenta el producto con un subtítulo concreto", () => {
    const vista = montar();
    expect(vista.get("h1").text()).toBe(
      "Menos pendientes. Más tiempo para tus clientes.",
    );
    expect(vista.get("h1 .tu-titulo-enfasis").text()).toBe("Más");
    expect(vista.get(".tu-hero-sub").text()).toBe(
      "Gestiona reservas, clases, membresías, cobros y tu equipo desde un solo lugar.",
    );
    expect(vista.get(".tu-hero-actions").text()).toContain(
      "Probar AgendaUno gratis",
    );
    vista.unmount();
  });
  it("muestra Pole dance en el hero y categorías específicas de acuáticas y salud", () => {
    const vista = montar();
    expect(vista.findAll(".tu-hero-foto img")[1]!.attributes("src")).toBe(
      "/assets/landing/disciplinas/pole-v1.jpg",
    );
    expect(vista.findAll(".tu-hero-foto").map((foto) => foto.text())).toEqual([
      "Barberías",
      "Pole dance",
      "CrossFit / HYROX",
    ]);
    expect(
      vista.findAll(".tu-hero-foto img")[1]!.attributes("fetchpriority"),
    ).toBe("high");
    const negocios = vista.findComponent(CarruselNegocios).props("negocios");
    expect(negocios.map((n) => n.nombre)).toEqual(
      expect.arrayContaining([
        "Psicólogos",
        "Dentistas",
        "Acuáticas",
        "Wellness",
        "Spas",
        "Terapeutas",
        "Nutriólogos",
        "CrossFit / HYROX",
      ]),
    );
    expect(new Set(negocios.map((n) => n.clave)).size).toBe(negocios.length);
    vista.unmount();
  });
  it("distingue la suscripción de los cobros propios y presenta las dos modalidades", () => {
    const vista = montar();
    expect(vista.get("#precios").text()).toContain("Por alumnos activos");
    expect(vista.get("#precios").text()).toContain("Citas por profesional");
    expect(vista.get("#precios .precio-importe strong").text()).toBe("$339");
    expect(vista.get("#precios .precio-impuestos").text()).toBe("+ IVA");
    expect(vista.text()).not.toContain(
      "Aún no se puede contratar con este esquema",
    );
    expect(vista.get("#precios").text()).toContain("son cobros distintos");
    expect(vista.text()).not.toContain("Tu equipo y administradores no inflan");
    expect(vista.get(".tu-hero-actions a[href]").attributes("href")).toBe(
      "#producto",
    );
    vista.unmount();
  });
  it("conecta ambas rutas comerciales con su ejemplo de agenda", async () => {
    const vista = montar();
    await vista.findAll(".modalidad-contenido a")[1]!.trigger("click");
    expect(vista.get(".demo-detalle h4").text()).toBe("Corte de cabello");
    await vista.findAll(".modalidad-contenido a")[0]!.trigger("click");
    expect(vista.get(".demo-detalle h4").text()).toBe("Pilates Reformer");
    vista.unmount();
  });
});
