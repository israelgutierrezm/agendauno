import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import { MODALIDADES, MODOS, type Modo } from "@/marketing/modalidades";
import NovedadesLanding from "./NovedadesLanding.vue";

/*
| «Lo nuevo» de /clases y /citas: seis novedades por modalidad, con su ejemplo, sus
| tres puntos y la nota de México donde algo lleva «*». Cada página habla solo de su
| modalidad.
*/

function montar(modo: Modo, slot?: string) {
  return mount(NovedadesLanding, {
    props: { modo },
    slots: slot ? { default: slot } : {},
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
    },
  });
}

interface TextoNovedad {
  titulo: string;
  texto: string;
  puntos: Record<"p1" | "p2" | "p3", string>;
}
const textos = (modo: Modo) =>
  es.landing[modo].novedades.items as Record<string, TextoNovedad>;

describe("«Lo nuevo» de cada modalidad", () => {
  it.each(MODOS)(
    "%s: seis novedades, cada una con su ejemplo, su título, su texto y tres puntos",
    (modo) => {
      expect(MODALIDADES[modo].novedades).toHaveLength(6);
      const vista = montar(modo);
      const tarjetas = vista.findAll("[data-novedad]");
      expect(tarjetas.map((n) => n.attributes("data-novedad"))).toEqual([
        ...MODALIDADES[modo].novedades,
      ]);
      for (const tarjeta of tarjetas) {
        const clave = tarjeta.attributes("data-novedad")!;
        const texto = textos(modo)[clave]!;
        expect(texto, clave).toBeDefined();
        expect(tarjeta.get("h3").text()).toBe(texto.titulo);
        expect(texto.texto.length, clave).toBeGreaterThan(60);
        expect(tarjeta.findAll(".novedad-puntos li")).toHaveLength(3);
        // El ejemplo es decorativo, pero existe y no está vacío.
        const visual = tarjeta.get(".novedad-visual");
        expect(visual.attributes("aria-hidden")).toBe("true");
        // El marco nunca hereda las clases de sus miniaturas absolutas.
        expect(visual.classes()).toEqual(["novedad-visual"]);
        expect(visual.attributes("data-visual")).toBeTruthy();
        expect(visual.text().length, clave).toBeGreaterThan(15);
      }
      // Un solo título de sección, con su énfasis.
      expect(vista.findAll("h2")).toHaveLength(1);
      expect(vista.get("h2 .tu-titulo-enfasis").text()).toBe(
        es.landing[modo].novedades.enfasis,
      );
      expect(vista.text()).not.toMatch(/landing\./);
      vista.unmount();
    },
  );

  it("clases habla solo de clases: Wellhub, TotalPass y Fitpass*, Wallet, widgets, app, bandeja y caja", () => {
    const vista = montar("clases");
    const texto = vista.text();
    expect(texto).not.toMatch(/\bcitas?\b|barber|profesional/i);
    expect(texto).not.toMatch(/crea tu cuenta|regístrate/i);
    // Fitpass solo en México (con «*»); Wellhub y TotalPass, según el país.
    const plataformas = textos("clases").plataformas!;
    expect(plataformas.titulo).toContain("Fitpass*");
    expect(plataformas.titulo).not.toMatch(/Wellhub\*|TotalPass\*/);
    // Fitpass no tiene API: sus reservas las registra recepción (ADR 0197).
    expect(plataformas.texto).toContain("recepción registra sus reservas");
    expect(texto).toContain("Apple Wallet y Google Wallet");
    expect(texto).toContain("WhatsApp e Instagram");
    expect(vista.get(".tu-nota-mexico").text()).toBe(es.landing.soloMexico);
    vista.unmount();
  });

  it("citas habla solo de citas: anticipo*, presupuestos*, factura automática*, «Agendar cita», bandeja y app", () => {
    const vista = montar("citas");
    const texto = vista.text();
    expect(texto).not.toMatch(/\bclases?\b|\bcupos?\b|lista de espera|alumno/i);
    // Los recordatorios siguen por correo: aquí no se prometen por otro canal.
    expect(texto).not.toMatch(
      /recordatorios? (por|en) (whatsapp|push|notificaci|la app)/i,
    );
    const t = textos("citas");
    for (const clave of ["anticipo", "presupuestos", "factura"]) {
      expect(t[clave]!.titulo, clave).toMatch(/\*$/);
    }
    expect(t.factura!.texto).toContain("CFDI de egreso");
    // Facturación y bandeja abren en Pro (ADR 0107).
    expect(t.factura!.puntos.p3).toContain("Pro");
    expect(t.bandeja!.texto).toContain("plan Pro");
    expect(vista.get(".tu-nota-mexico").text()).toBe(es.landing.soloMexico);
    vista.unmount();
  });

  it("sin íconos teñidos ni marcas de texto: estados como punto + texto", () => {
    for (const modo of MODOS) {
      const vista = montar(modo);
      expect(vista.text()).not.toMatch(/[✓✔✕☰→]/);
      // Los ejemplos no llevan la marca (la cambia i18n, no el componente).
      expect(vista.find(".novedad-visual").text()).not.toMatch(
        /AgendaUno|TurnoUno/,
      );
      vista.unmount();
    }
  });

  it("pone el botón que le pasa la página al pie de la sección", () => {
    const vista = montar("clases", '<a data-cta="whats_new">Ir</a>');
    expect(vista.get(".novedades-pie [data-cta='whats_new']").text()).toBe(
      "Ir",
    );
    vista.unmount();
    expect(montar("citas").find(".novedades-pie").exists()).toBe(false);
  });
});
