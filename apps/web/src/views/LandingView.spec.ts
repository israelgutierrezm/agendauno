import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { createMemoryHistory, createRouter } from "vue-router";

import es from "@/i18n/locales/es-MX";
import { trackEvent } from "@/lib/analytics";
import {
  ETIQUETA_MENU,
  NOMBRE_MODALIDAD,
  solucionesDe,
  type Modo,
} from "@/marketing/modalidades";
import {
  PRECIOS_POR_OMISION,
  desdeCitas,
  dolares,
  rangosClases,
} from "@/marketing/precios";
import { rutaSolucion } from "@/marketing/soluciones";
import { rutasComerciales } from "@/router/comerciales";
import CarruselNegocios from "@/components/CarruselNegocios.vue";
import FuncionesLanding from "@/components/FuncionesLanding.vue";
import ModalidadesLanding from "@/components/ModalidadesLanding.vue";
import PreciosLanding from "@/components/PreciosLanding.vue";
import ProductoDemo from "@/components/ProductoDemo.vue";
import LandingView from "./LandingView.vue";
import fuente from "./LandingView.vue?raw";

/*
| La portada «/» es corta: el visitante elige «Doy clases» o «Atiendo con cita» y cada
| modalidad tiene su landing completa (/clases, /citas). Aquí no van secciones de una
| sola modalidad. Router real (las rutas comerciales de la app) para ver los href.
*/

vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
afterEach(() => {
  vi.unstubAllGlobals();
  vi.clearAllMocks();
});

const Vacia = { render: () => null };

async function montar() {
  vi.stubGlobal(
    "IntersectionObserver",
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  );
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      ...rutasComerciales({
        landing: Vacia,
        modalidad: Vacia,
        solucion: Vacia,
      }),
      { path: "/registro", name: "registro", component: Vacia },
    ],
  });
  await router.push("/");
  await router.isReady();
  return mount(LandingView, {
    global: {
      plugins: [
        router,
        createI18n({ legacy: false, locale: "es", messages: { es } }),
      ],
    },
  });
}

const modos: Modo[] = ["clases", "citas"];

describe("portada comercial", () => {
  it("tiene un solo h1, con la frase de marca", async () => {
    const vista = await montar();
    expect(vista.findAll("h1")).toHaveLength(1);
    expect(vista.get("h1").text()).toBe(es.landing.titulo);
    expect(vista.get("h1 .tu-titulo-enfasis").text()).toBe("Más");
    vista.unmount();
  });

  it("ofrece dos botones grandes, «Doy clases» y «Atiendo con cita», a /clases y /citas", async () => {
    const vista = await montar();
    const botones = vista.findAll(".tu-hero .tu-hero-opcion");
    expect(botones).toHaveLength(2);
    expect(botones.map((b) => b.attributes("href"))).toEqual([
      "/clases",
      "/citas",
    ]);
    expect(botones[0]!.text()).toContain("Doy clases");
    expect(botones[1]!.text()).toContain("Atiendo con cita");
    // En azul (`tu-btn-azul`, `--primario`); el rosa queda para el menú y el cierre.
    botones.forEach((b) => expect(b.classes()).toContain("tu-btn-azul"));
    vista
      .findAll(".tu-final-acciones a")
      .forEach((b) => expect(b.classes()).not.toContain("tu-btn-azul"));
    // Agrupados bajo la pregunta que los nombra.
    const grupo = vista.get(".tu-hero-opciones");
    expect(grupo.attributes("role")).toBe("group");
    expect(vista.get(`#${grupo.attributes("aria-labelledby")}`).text()).toBe(
      es.landing.portada.elegir,
    );
    vista.unmount();
  });

  it("mide la modalidad elegida en el hero con `mode`", async () => {
    const vista = await montar();
    const botones = vista.findAll(".tu-hero-opcion");
    await botones[1]!.trigger("click");
    await botones[0]!.trigger("click");
    await flushPromises();
    expect(vi.mocked(trackEvent).mock.calls).toEqual([
      [
        "marketing_business_mode_selected",
        { mode: "citas", placement: "hero" },
      ],
      [
        "marketing_business_mode_selected",
        { mode: "clases", placement: "hero" },
      ],
    ]);
    vista.unmount();
  });

  it("explica bajo los botones que cada negocio elige una modalidad", async () => {
    const vista = await montar();
    expect(vista.get(".tu-hero-modalidad").text()).toBe(
      es.landing.portada.unaModalidad,
    );
    expect(vista.text()).not.toContain("Una sola herramienta");
    expect(vista.get(".tu-hero-proof").text()).toContain("30 días");
    expect(vista.get(".tu-confianza").text()).toContain("30 días");
    expect(vista.text()).not.toMatch(/14 días/);
    vista.unmount();
  });

  it("el sello del precio nombra los dos modelos, sin decir que todo es por profesional", async () => {
    const vista = await montar();
    const sellos = vista.get(".tu-confianza").text();
    expect(sellos).toContain("Precio por alumnos activos o por profesionales");
    expect(sellos).not.toMatch(/clases o citas por profesional/i);
    vista.unmount();
  });

  it("conserva las anclas del menú: producto (modalidades), precios y soluciones", async () => {
    const vista = await montar();
    const ids = vista
      .findAll("section[id]")
      .map((seccion) => seccion.attributes("id"));
    expect(ids).toEqual(["producto", "precios", "soluciones"]);
    expect(
      vista.get("#producto").findComponent(ModalidadesLanding).exists(),
    ).toBe(true);
    vista.unmount();
  });

  it("mide la modalidad elegida en las tarjetas de modalidades", async () => {
    const vista = await montar();
    vista.findComponent(ModalidadesLanding).vm.$emit("elegir", "citas");
    expect(trackEvent).toHaveBeenCalledWith(
      "marketing_business_mode_selected",
      { mode: "citas", placement: "business_modes" },
    );
    vista.unmount();
  });

  it("muestra los precios con dos tarjetas «desde…» que llevan a cada modalidad", async () => {
    const vista = await montar();
    const tarjetas = vista.findAll("#precios .tu-portada-precio");
    expect(tarjetas).toHaveLength(2);
    expect(tarjetas.map((t) => t.get("strong").text())).toEqual([
      dolares(rangosClases(PRECIOS_POR_OMISION.clases.bandas)[0]!.subtotal),
      dolares(desdeCitas(PRECIOS_POR_OMISION.citas.niveles).individual),
    ]);
    expect(tarjetas[0]!.text()).toContain(NOMBRE_MODALIDAD.clases);
    expect(tarjetas[0]!.text()).toContain("Por alumnos activos");
    expect(tarjetas[0]!.text()).toContain("Hasta 40 alumnos activos");
    expect(tarjetas[1]!.text()).toContain(NOMBRE_MODALIDAD.citas);
    expect(tarjetas[1]!.text()).toContain("Por plan y profesionales");
    expect(tarjetas[1]!.text()).toContain("1 profesional");
    tarjetas.forEach((t) => expect(t.text()).toContain("Más impuestos"));
    // La moneda es la de la tarifa publicada (el respaldo, en dólares).
    tarjetas.forEach((t) => expect(t.text()).toContain("USD / mes"));
    // Flechas en SVG, no «›» de texto.
    tarjetas.forEach((t) => expect(t.text()).not.toMatch(/[›→]/));
    // Borde de arriba rosa (jsdom no aplica el CSS con scope: se revisa la regla).
    expect(fuente).toMatch(
      /\.tu-portada-precio \{[^}]*border-top: 3px solid var\(--marketing-cta\);/,
    );
    const enlaces = tarjetas.map((t) => t.get("a"));
    expect(enlaces.map((a) => a.attributes("href"))).toEqual([
      "/clases#precios",
      "/citas#precios",
    ]);
    await enlaces[1]!.trigger("click");
    expect(trackEvent).toHaveBeenCalledWith("marketing_cta_clicked", {
      placement: "pricing_card",
      destination: "pricing",
      mode: "citas",
    });
    vista.unmount();
  });

  it("presenta los giros en dos columnas, cada modalidad con sus páginas", async () => {
    const vista = await montar();
    const columnas = vista.findAll("#soluciones .soluciones-columna");
    expect(columnas.map((c) => c.attributes("data-modo"))).toEqual(modos);
    columnas.forEach((columna, i) => {
      const modo = modos[i]!;
      expect(columna.get("h3").text()).toBe(ETIQUETA_MENU[modo]);
      expect(
        columna
          .findAll(".soluciones-enlaces a")
          .map((a) => a.attributes("href")),
      ).toEqual(solucionesDe(modo).map((s) => rutaSolucion(s.slug)));
      // Al pie, el enlace a la landing de su modalidad.
      expect(
        columna.get(".tu-portada-giros-modalidad").attributes("href"),
      ).toBe(`/${modo}`);
    });
    // La nota de alcance para la salud va con las citas.
    expect(columnas[0]!.find(".tu-alcance-salud").exists()).toBe(false);
    expect(columnas[1]!.get(".tu-alcance-salud").text()).toContain(
      es.landing.paraQuien.saludTitulo,
    );
    vista.unmount();
  });

  it("responde preguntas generales: prueba, cancelación, datos, dos negocios y cambiar de modalidad", async () => {
    const vista = await montar();
    const preguntas = vista.findAll("details");
    expect(preguntas.map((p) => p.attributes("data-pregunta"))).toEqual([
      "prueba",
      "cancelacion",
      "datos",
      "dosNegocios",
      "cambiarModalidad",
    ]);
    // Quién cambia la modalidad se explica aquí (ya no en el registro).
    expect(preguntas[4]!.get("summary").text()).toBe(
      "¿Puedo cambiar de clases a citas después?",
    );
    expect(preguntas[4]!.get("p").text()).toContain(
      "Solo AgendaUno puede cambiar la modalidad",
    );
    expect(preguntas[4]!.get("p").text()).toContain(
      "solo antes de que empieces a operar",
    );
    preguntas.forEach((p) =>
      expect(p.get("summary").text()).toContain(
        es.landing.portada.faq[
          p.attributes("data-pregunta") as keyof typeof es.landing.portada.faq
        ],
      ),
    );
    expect(preguntas[0]!.text()).toContain("30 días");
    expect(preguntas[3]!.text()).toContain("registra dos negocios");
    // La marca de abrir es una flecha SVG, no un «+» de texto.
    preguntas.forEach((p) => {
      expect(p.get("summary").text()).not.toMatch(/\+$/);
      expect(p.find("summary svg.pregunta-marca").exists()).toBe(true);
    });
    vista.unmount();
  });

  it("cierra con dos botones al registro, cada uno con su modalidad", async () => {
    const vista = await montar();
    const botones = vista.findAll(".tu-final-acciones a");
    expect(botones.map((b) => b.attributes("href"))).toEqual([
      "/registro?modo=clases",
      "/registro?modo=citas",
    ]);
    expect(botones.map((b) => b.text())).toEqual([
      "Probar con clases",
      "Probar con citas",
    ]);
    await botones[0]!.trigger("click");
    expect(trackEvent).toHaveBeenCalledWith("marketing_cta_clicked", {
      placement: "final",
      destination: "register",
      mode: "clases",
    });
    vista.unmount();
  });

  it("no trae secciones de una sola modalidad", async () => {
    const vista = await montar();
    for (const componente of [
      ProductoDemo,
      PreciosLanding,
      FuncionesLanding,
      CarruselNegocios,
    ]) {
      expect(vista.findComponent(componente).exists()).toBe(false);
    }
    expect(vista.find("#como-funciona").exists()).toBe(false);
    expect(vista.find("#operacion").exists()).toBe(false);
    expect(vista.text()).not.toMatch(/anticipos en línea|WhatsApp/i);
    vista.unmount();
  });

  it("alterna el fondo del hero y la superficie entre secciones", async () => {
    const vista = await montar();
    const bandas = vista.findAll("section.tu-banda");
    expect(bandas).toHaveLength(6);
    bandas.forEach((banda, indice) => {
      const fondo = indice % 2 === 0 ? "var(--fondo)" : "var(--superficie)";
      expect(banda.attributes("style")).toContain(`background: ${fondo}`);
    });
    vista.unmount();
  });
});
