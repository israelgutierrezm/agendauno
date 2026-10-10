import { existsSync } from "node:fs";
import { resolve } from "node:path";
import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { createMemoryHistory, createRouter } from "vue-router";

import es from "@/i18n/locales/es-MX";
import { trackEvent } from "@/lib/analytics";
import {
  IMAGEN_NEGOCIO,
  MODALIDADES,
  NEGOCIOS_POR_MODO,
  imagenNegocio,
  type Modo,
} from "@/marketing/modalidades";
import { rutasComerciales } from "@/router/comerciales";
import CarruselNegocios from "@/components/CarruselNegocios.vue";
import FuncionesLanding from "@/components/FuncionesLanding.vue";
import PreciosLanding from "@/components/PreciosLanding.vue";
import ProductoDemo from "@/components/ProductoDemo.vue";
import SolucionesEnlaces from "@/components/SolucionesEnlaces.vue";
import ModalidadView from "./ModalidadView.vue";

/*
| /clases y /citas (una sola vista con `modo`): cada página habla solo de su
| modalidad, lleva el registro con `?modo=` y cierra con un enlace discreto a la otra.
| Los componentes con `modo` de otras piezas (demo, precios, funciones, carrusel,
| giros) se prueban en sus propios specs; aquí se revisa que reciban su modo.
*/

vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
beforeEach(() => {
  vi.mocked(trackEvent).mockClear();
  vi.stubGlobal(
    "IntersectionObserver",
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  );
});
afterEach(() => vi.unstubAllGlobals());

const MODOS_PRUEBA: Modo[] = ["clases", "citas"];
const vacia = { render: () => null };
const publico = (ruta: string) => resolve(process.cwd(), `public${ruta}`);

function router() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      ...rutasComerciales({
        landing: vacia,
        modalidad: vacia,
        solucion: vacia,
      }),
      { path: "/registro", name: "registro", component: vacia },
    ],
  });
}

function montar(modo: Modo, { completa = false } = {}) {
  return mount(ModalidadView, {
    props: { modo },
    global: {
      plugins: [
        router(),
        createI18n({ legacy: false, locale: "es", messages: { es } }),
      ],
      stubs: completa
        ? {}
        : {
            ProductoDemo: true,
            PreciosLanding: true,
            FuncionesLanding: true,
            CarruselNegocios: true,
            SolucionesEnlaces: true,
          },
    },
  });
}

/** El texto de la página sin el enlace discreto a la otra modalidad. */
function textoPropio(vista: ReturnType<typeof montar>): string {
  return vista.text().replace(vista.get(".tu-modalidad-otra").text(), "");
}

describe("landing de una modalidad (/clases y /citas)", () => {
  it.each(MODOS_PRUEBA)("%s: un solo h1, su nombre y su data-modo", (modo) => {
    const vista = montar(modo);
    expect(vista.get(".tu-landing.tu-modalidad").attributes("data-modo")).toBe(
      modo,
    );
    expect(vista.findAll("h1")).toHaveLength(1);
    expect(vista.get("h1").text()).toBe(es.landing[modo].hero.titulo);
    expect(vista.get("h1 .tu-titulo-enfasis").text()).toBe(
      es.landing[modo].hero.enfasis,
    );
    expect(vista.get(".tu-modalidad-nombre").text()).toBe(
      MODALIDADES[modo].nombre,
    );
    // Ninguna clave de i18n sin texto.
    expect(vista.text()).not.toMatch(/landing\.|escaparate\./);
    vista.unmount();
  });

  it.each(MODOS_PRUEBA)(
    "%s: las secciones con sus anclas, en bandas que alternan",
    (modo) => {
      const vista = montar(modo);
      const ids = vista
        .findAll("section.tu-banda[id]")
        .map((s) => s.attributes("id"));
      expect(ids).toEqual([
        "producto",
        "soluciones",
        "como-funciona",
        "para-quien",
        "operacion",
        "precios",
        "pagina-publica",
        "preguntas",
      ]);
      const bandas = vista.findAll("section.tu-banda");
      expect(bandas).toHaveLength(10);
      bandas.forEach((banda, i) => {
        const fondo = i % 2 === 0 ? "var(--fondo)" : "var(--superficie)";
        expect(banda.attributes("style")).toContain(`background: ${fondo}`);
      });
      vista.unmount();
    },
  );

  it.each(MODOS_PRUEBA)(
    "%s: todos los enlaces al registro llevan ?modo= y miden con mode",
    async (modo) => {
      const vista = montar(modo);
      await flushPromises();
      const registro = vista.findAll('a[href^="/registro"]');
      expect(registro.length).toBeGreaterThanOrEqual(5);
      for (const enlace of registro) {
        expect(enlace.attributes("href")).toBe(`/registro?modo=${modo}`);
      }
      await vista.get('[data-cta="hero"]').trigger("click");
      expect(trackEvent).toHaveBeenCalledWith("marketing_cta_clicked", {
        placement: "hero",
        destination: "register",
        mode: modo,
      });
      await vista.get('[data-cta="final"]').trigger("click");
      expect(trackEvent).toHaveBeenCalledWith("marketing_cta_clicked", {
        placement: "final",
        destination: "register",
        mode: modo,
      });
      vista.unmount();
    },
  );

  it.each([
    ["clases", "/citas", "citas"],
    ["citas", "/clases", "clases"],
  ] as const)(
    "%s cierra con un enlace discreto a %s",
    async (modo, ruta, otra) => {
      const vista = montar(modo);
      await flushPromises();
      const enlace = vista.get(".tu-modalidad-otra a");
      expect(enlace.attributes("href")).toBe(ruta);
      expect(enlace.text()).toContain(es.landing[modo].final.otraEnlace);
      await enlace.trigger("click");
      expect(trackEvent).toHaveBeenCalledWith(
        "marketing_business_mode_selected",
        { mode: otra, placement: "mode_page_footer" },
      );
      vista.unmount();
    },
  );

  it("/citas no habla de clases, cupos ni lista de espera", () => {
    const vista = montar("citas");
    const texto = textoPropio(vista);
    expect(texto).not.toMatch(/\bclases?\b/i);
    expect(texto).not.toMatch(/\bcupos?\b/i);
    expect(texto).not.toMatch(/lista de espera/i);
    expect(texto).not.toMatch(/alumno/i);
    // Lo veraz de citas: sin anticipos, recordatorios solo por correo.
    expect(texto).not.toMatch(/anticipos?\b/i);
    expect(texto).not.toMatch(
      /recordatorios? (por|en) (whatsapp|push|notificaci|la app)/i,
    );
    expect(texto).toMatch(/recordatorios? (llegan )?por correo/i);
    expect(texto).toMatch(/Cualquier profesional/);
    vista.unmount();
  });

  it("/clases no habla de citas, barberos ni profesionales", () => {
    const vista = montar("clases");
    const texto = textoPropio(vista);
    expect(texto).not.toMatch(/\bcitas?\b/i);
    expect(texto).not.toMatch(/barber/i);
    expect(texto).not.toMatch(/profesional/i);
    // Sin autorregistro de alumnos: la página pública pide acceso.
    expect(texto).not.toMatch(/crea tu cuenta|regístrate/i);
    // El QR es pase de entrada; la asistencia se pasa en lista con retardos.
    expect(texto).toMatch(/Pase QR de entrada/);
    expect(texto).toMatch(/retardo/i);
    vista.unmount();
  });

  it("la página pública de clases termina en «Pedir acceso / Ya soy alumno»", () => {
    const vista = montar("clases");
    const pagina = vista.get("#pagina-publica");
    expect(pagina.text()).toContain(es.escaparate.pedirAcceso);
    expect(pagina.text()).toContain(es.escaparate.yaSoyAlumno);
    expect(pagina.text()).toMatch(/lugares|Lleno/);
    // Los rótulos de la demo salen de i18n (los datos ficticios, del componente).
    const demo = es.landing.clases.pagina.demo;
    expect(pagina.text()).toContain(demo.abierta);
    expect(pagina.text()).toContain(demo.semana);
    expect(pagina.text()).toContain("3 lugares");
    expect(pagina.text()).toContain(demo.lleno);
    vista.unmount();
  });

  it("la página pública de citas: servicio → profesional (con «Cualquier profesional») → hora", async () => {
    const vista = montar("citas");
    const pagina = vista.get("#pagina-publica");
    const pasos = pagina.findAll(".demo-citas-paso").map((p) => p.text());
    expect(pasos).toEqual(["1 Servicio", "2 Con quién", "3 Hora · Hoy"]);
    // Rótulos y etiqueta accesible desde i18n.
    const demo = es.landing.citas.pagina.demo;
    expect(pagina.get(".demo-citas").attributes("aria-label")).toBe(demo.aria);
    expect(pagina.text()).toContain(demo.ejemplo);
    expect(pagina.text()).toContain(demo.confirmar);
    const cualquiera = pagina.get('[data-profesional="cualquiera"]');
    expect(cualquiera.text()).toBe("Cualquier profesional");
    expect(cualquiera.attributes("aria-pressed")).toBe("true");
    // Con cualquiera, las horas de todo el equipo; con Luis, solo las suyas.
    const horas = () => pagina.findAll(".demo-citas-hora").map((h) => h.text());
    expect(horas()).toEqual([
      "10:00",
      "11:30",
      "13:00",
      "16:00",
      "17:30",
      "18:30",
    ]);
    await pagina.get('[data-profesional="luis"]').trigger("click");
    expect(horas()).toEqual(["11:30", "16:00"]);
    // La hora elegida (17:30) no estaba libre con Luis: pasa a la primera suya.
    expect(pagina.get(".demo-citas-resumen").text()).toBe(
      "Corte y barba · Hoy 11:30 · Luis",
    );
    vista.unmount();
  });

  it("cambiar de modalidad no arrastra el estado de la página", async () => {
    const vista = montar("citas");
    await vista.get('[data-profesional="marco"]').trigger("click");
    expect(
      vista.get('[data-profesional="marco"]').attributes("aria-pressed"),
    ).toBe("true");
    await vista.setProps({ modo: "clases" });
    expect(vista.find(".demo-citas").exists()).toBe(false);
    await vista.setProps({ modo: "citas" });
    expect(
      vista.get('[data-profesional="cualquiera"]').attributes("aria-pressed"),
    ).toBe("true");
    vista.unmount();
  });

  it("pone la nota de México donde se menciona el cobro en línea", () => {
    const citas = montar("citas");
    for (const seccion of ["#como-funciona", "#operacion", "#pagina-publica"]) {
      expect(citas.find(`${seccion} .tu-nota-mexico`).exists(), seccion).toBe(
        true,
      );
    }
    expect(citas.get("#preguntas").text()).toContain("Sí*.");
    expect(citas.find("#preguntas .tu-nota-mexico").exists()).toBe(true);
    // La nota de salud solo en citas.
    expect(citas.find("#para-quien .tu-alcance-salud").exists()).toBe(true);
    citas.unmount();

    const clases = montar("clases");
    expect(clases.find("#como-funciona .tu-nota-mexico").exists()).toBe(true);
    expect(clases.find("#preguntas .tu-nota-mexico").exists()).toBe(false);
    expect(clases.find("#para-quien .tu-alcance-salud").exists()).toBe(false);
    clases.unmount();
  });

  it.each(MODOS_PRUEBA)(
    "%s: las preguntas y los pasos son los declarados en MODALIDADES",
    (modo) => {
      const vista = montar(modo);
      const preguntas = vista.findAll("#preguntas details");
      expect(preguntas.map((p) => p.attributes("data-pregunta"))).toEqual([
        ...MODALIDADES[modo].preguntas,
      ]);
      for (const p of preguntas) {
        expect(p.get("summary").text().length).toBeGreaterThan(10);
        expect(p.get("p").text().length).toBeGreaterThan(40);
      }
      expect(
        vista
          .findAll("#como-funciona [data-paso]")
          .map((p) => p.attributes("data-paso")),
      ).toEqual([...MODALIDADES[modo].pasos]);
      expect(vista.findAll("#operacion .tu-check-item")).toHaveLength(
        MODALIDADES[modo].operacion.length,
      );
      vista.unmount();
    },
  );

  it.each(MODOS_PRUEBA)(
    "%s: los componentes reciben su modo y el carrusel solo sus giros",
    (modo) => {
      const vista = montar(modo);
      for (const componente of [
        ProductoDemo,
        PreciosLanding,
        FuncionesLanding,
        SolucionesEnlaces,
      ]) {
        expect(vista.findComponent(componente).props("modo")).toBe(modo);
      }
      const negocios = vista
        .findComponent(CarruselNegocios)
        .props("negocios") as { clave: string; src: string; alt: string }[];
      expect(negocios.map((n) => n.clave)).toEqual([
        ...NEGOCIOS_POR_MODO[modo],
      ]);
      for (const n of negocios) {
        expect(existsSync(publico(n.src)), n.src).toBe(true);
        expect(n.alt.length).toBeGreaterThan(10);
      }
      vista.unmount();
    },
  );

  it.each(MODOS_PRUEBA)(
    "%s: cada imagen tiene texto alternativo y existe en public/",
    (modo) => {
      const vista = montar(modo);
      const imagenes = vista.findAll("img");
      expect(imagenes.length).toBeGreaterThanOrEqual(4);
      for (const img of imagenes) {
        expect(
          img.attributes("alt")?.length,
          img.attributes("src"),
        ).toBeGreaterThan(10);
        expect(
          existsSync(publico(img.attributes("src")!)),
          img.attributes("src"),
        ).toBe(true);
      }
      // La foto de recepción es la de su modalidad (citas no usa la de yoga).
      expect(vista.get("#operacion img").attributes("src")).toBe(
        MODALIDADES[modo].fotos.recepcion.src,
      );
      // El collage: la foto del centro se pide primero.
      expect(
        vista.findAll(".tu-hero-foto img")[1]!.attributes("fetchpriority"),
      ).toBe("high");
      vista.unmount();
    },
  );

  it.each(MODOS_PRUEBA)(
    "%s: el contenido declarado apunta a fotos y textos que existen",
    (modo) => {
      const contenido = MODALIDADES[modo];
      for (const clave of NEGOCIOS_POR_MODO[modo]) {
        expect(IMAGEN_NEGOCIO[clave], clave).toBeDefined();
        expect(existsSync(publico(imagenNegocio(clave))), clave).toBe(true);
      }
      // El collage sale de los negocios de su modalidad.
      for (const clave of contenido.hero.collage) {
        expect(NEGOCIOS_POR_MODO[modo]).toContain(clave);
      }
      const escritura = es.landing.heroEscritura.negocios as Record<
        string,
        string
      >;
      for (const clave of contenido.hero.escritura) {
        expect(escritura[clave], clave).toBeDefined();
      }
      expect(contenido.otra).not.toBe(modo);
      // La foto de recepción no repite el hero ni la que abre el carrusel, que va
      // justo antes de su banda.
      const repetidas = [
        ...contenido.hero.collage,
        NEGOCIOS_POR_MODO[modo][0]!,
      ].map(imagenNegocio);
      expect(repetidas).not.toContain(contenido.fotos.recepcion.src);
    },
  );

  it.each(MODOS_PRUEBA)(
    "%s completa (con sus componentes) sigue hablando solo de su modalidad",
    async (modo) => {
      const vista = montar(modo, { completa: true });
      await flushPromises();
      const texto = textoPropio(vista);
      if (modo === "citas") {
        expect(texto).not.toMatch(/\bclases?\b|\bcupos?\b|lista de espera/i);
      } else {
        expect(texto).not.toMatch(/\bcitas?\b|barber|profesional/i);
      }
      expect(vista.findAll("h1")).toHaveLength(1);
      for (const enlace of vista.findAll('a[href^="/registro"]')) {
        expect(enlace.attributes("href")).toBe(`/registro?modo=${modo}`);
      }
      vista.unmount();
    },
  );

  it.each(MODOS_PRUEBA)(
    "%s: el botón del hero va en azul y el cierre se queda rosa",
    (modo) => {
      const vista = montar(modo);
      expect(vista.get('[data-cta="hero"]').classes()).toEqual(
        expect.arrayContaining(["tu-btn", "tu-btn-primario", "tu-btn-azul"]),
      );
      expect(vista.get('[data-cta="final"]').classes()).not.toContain(
        "tu-btn-azul",
      );
      // A media página también azul: el rosa es solo del menú y el cierre.
      for (const cta of ["product_demo", "public_page"]) {
        expect(vista.get(`[data-cta="${cta}"]`).classes()).toContain(
          "tu-btn-azul",
        );
      }
      // Las tarjetas de precio ya invitan a probar: sin un bloque repetido abajo.
      expect(vista.find(".tu-precio-prueba").exists()).toBe(false);
      // Flechas de los enlaces en SVG, no como texto.
      for (const enlace of [".tu-modalidad-otra", ".tu-link-flecha"]) {
        expect(vista.get(enlace).text()).not.toMatch(/[›→↗]/);
        expect(vista.find(`${enlace} svg`).exists()).toBe(true);
      }
      vista.unmount();
    },
  );

  it.each(MODOS_PRUEBA)(
    "%s: pregunta quién cambia la modalidad (ya no lo dice el registro)",
    (modo) => {
      const vista = montar(modo);
      const pregunta = vista.get('#preguntas [data-pregunta="modalidad"]');
      expect(pregunta.get("summary").text()).toBe(
        "¿Puedo cambiar de modalidad después?",
      );
      expect(pregunta.get("p").text()).toContain(
        "Solo AgendaUno puede cambiar la modalidad de tu negocio, y solo antes de que empieces a operar",
      );
      vista.unmount();
    },
  );

  it("el botón del carrusel manda el giro que la persona eligió, no el que gira solo", async () => {
    const vista = montar("citas", { completa: true });
    await flushPromises();
    const boton = () => vista.get('[data-cta="business_carousel"]');
    // Sin elegir, solo la modalidad.
    expect(boton().attributes("href")).toBe("/registro?modo=citas");
    const barberia = vista
      .findAll(".orbita-categorias button")
      .find((b) => b.text() === es.landing.paraQuien.negocios.barberia.nombre)!;
    await barberia.trigger("click");
    expect(boton().attributes("href")).toBe(
      "/registro?modo=citas&giro=barberia",
    );
    await boton().trigger("click");
    expect(trackEvent).toHaveBeenCalledWith("marketing_cta_clicked", {
      placement: "business_carousel",
      destination: "register",
      mode: "citas",
      business_profile: "barberia",
    });
    // Wellness es un spa o centro de bienestar en el registro.
    await vista
      .findAll(".orbita-categorias button")
      .find((b) => b.text() === es.landing.paraQuien.negocios.wellness.nombre)!
      .trigger("click");
    expect(boton().attributes("href")).toBe("/registro?modo=citas&giro=spa");
    // «Peluquerías y estéticas» junta dos giros: solo la modalidad.
    await vista
      .findAll(".orbita-categorias button")
      .find((b) => b.text() === es.landing.paraQuien.negocios.estetica.nombre)!
      .trigger("click");
    expect(boton().attributes("href")).toBe("/registro?modo=citas");
    vista.unmount();
  });
});
