import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import PreciosLanding from "./PreciosLanding.vue";
import fuente from "./PreciosLanding.vue?raw";
import { trackEvent } from "@/lib/analytics";
import type { Modo } from "@/marketing/modalidades";
import { bandasEstudios, ejemplosCitas, pesos } from "@/marketing/precios";

vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
const montar = () =>
  mount(PreciosLanding, {
    global: { stubs: { RouterLink: { template: "<a><slot /></a>" } } },
  });
// El destino del RouterLink queda en data-to para revisar el `?modo=` del registro.
const montarFijo = (modo: Modo) =>
  mount(PreciosLanding, {
    props: { modo },
    global: {
      stubs: {
        RouterLink: {
          props: ["to"],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
    },
  });

describe("precios públicos", () => {
  it("muestra el precio sin IVA y la leyenda debajo, con prueba sin tarjeta", () => {
    const vista = montar();
    expect(vista.findAll("article")).toHaveLength(3);
    expect(vista.get('button[aria-pressed="true"] strong').text()).toBe(
      "Por alumnos activos",
    );
    expect(vista.get(".precio-importe strong").text()).toBe("$339");
    expect(vista.get(".precio-impuestos").text()).toBe("+ IVA");
    expect(vista.text()).not.toContain("$393.24");
    expect(vista.text()).not.toContain("IVA incluido");
    expect(vista.get("article").text()).toContain("Probar 30 días gratis");
    expect(vista.get("article").text()).toContain("Sin tarjeta");
    expect(vista.findAll("tbody tr")).toHaveLength(6);
    expect(vista.get(".precios-contacto a").attributes("href")).toContain(
      "mailto:ventas@agendauno.mx",
    );
    expect(vista.get(".precios-contacto").text()).toContain(
      "Más de 2,000 alumnos activos",
    );
    expect(vista.text()).not.toContain("$1,359");
    expect(vista.text()).toContain(
      "Sin alumnos activos, la renta por uso es $0",
    );
    vista.unmount();
  });
  it("presenta ambos modelos y sus negocios antes de elegir", () => {
    const vista = montar();
    const modelos = vista.findAll(".precios-selector button");
    expect(modelos).toHaveLength(2);
    expect(modelos[0]!.text()).toContain("Por alumnos activos");
    for (const negocio of ["Pilates", "Pole dance", "acuáticas", "baile"]) {
      expect(modelos[0]!.text()).toContain(negocio);
    }
    expect(modelos[1]!.text()).toContain("Por profesionales");
    for (const negocio of [
      "Barberías",
      "estéticas",
      "psicólogos",
      "dentistas",
      "nutriólogos",
    ]) {
      expect(modelos[1]!.text()).toContain(negocio);
    }
    expect(modelos[0]!.attributes("aria-pressed")).toBe("true");
    expect(modelos[1]!.attributes("aria-pressed")).toBe("false");
    vista.unmount();
  });
  it("cambia a citas sin distinguir jornadas ni ofrecer clases o talleres", async () => {
    const vista = montar();
    await vista.findAll("button")[1]!.trigger("click");
    expect(vista.get('button[aria-pressed="true"] strong').text()).toBe(
      "Por profesionales",
    );
    expect(vista.get(".precios-intro").text()).toContain("$269");
    expect(vista.text()).not.toMatch(
      /medio tiempo|tiempo completo|equivalente|\$134\.50/i,
    );
    expect(vista.get(".precio-importe strong").text()).toBe("$269");
    expect(vista.findAll(".precio-importe strong")[1]!.text()).toBe("$495");
    expect(vista.findAll(".precio-importe strong")[2]!.text()).toBe("$630");
    expect(vista.findAll(".precio-impuestos").map((n) => n.text())).toEqual([
      "+ IVA",
      "+ IVA",
      "+ IVA",
    ]);
    // Un negocio de citas no da clases (ADR 0104): sin el cargo por reservas grupales.
    expect(vista.text()).not.toContain("$9 + IVA");
    expect(vista.text()).not.toMatch(/talleres|reservas grupales/i);
    expect(vista.text()).not.toMatch(/en preparación/i);
    await vista.findAll("button")[0]!.trigger("click");
    expect(vista.get("article").text()).toContain("1–49 alumnos activos");
    vista.unmount();
  });
  it("conserva las tarifas verificadas, sin confundir tramos marginales con precio unitario", () => {
    expect(bandasEstudios.map((b) => b.subtotal)).toEqual([
      33900, 63900, 90900, 178900, 264900, 288900,
    ]);
    expect(ejemplosCitas.map((b) => b.subtotal)).toEqual([
      26900,
      26900 + 22600,
      26900 + 22600 + 13500,
    ]);
    expect(pesos(15602)).toBe("$156.02");
  });
  it("nombra las modalidades igual que la portada y el registro, con la nota de México", () => {
    const vista = montar();
    expect(
      vista.findAll(".precios-modelo-titulo").map((n) => n.text()),
    ).toEqual(["Clases con cupo", "Citas 1 a 1"]);
    expect(vista.get(".precio-contexto").text()).toBe("Clases con cupo");
    expect(vista.text()).toContain("pagos en línea*");
    expect(vista.get(".tu-nota-mexico").text()).toBe(
      "* Solo para clientes de México.",
    );
    vista.unmount();
  });
  it("con modo clases queda fijo: sin selector y el registro lleva ?modo=clases", async () => {
    const vista = montarFijo("clases");
    expect(vista.find(".precios-selector").exists()).toBe(false);
    expect(vista.get(".precios-modelo").text()).toBe(
      "Clases con cupo · Por alumnos activos",
    );
    expect(vista.findAll("article")).toHaveLength(3);
    expect(vista.get(".precio-importe strong").text()).toBe("$339");
    expect(vista.findAll("tbody tr")).toHaveLength(6);
    expect(vista.find(".precios-contacto").exists()).toBe(true);
    expect(vista.text()).not.toMatch(/profesionales activos/i);
    expect(vista.get(".tu-nota-mexico").exists()).toBe(true);
    const cta = vista.get(".precio-cta");
    expect(JSON.parse(cta.attributes("data-to")!)).toEqual({
      name: "registro",
      query: { modo: "clases" },
    });
    await cta.trigger("click");
    expect(trackEvent).toHaveBeenCalledWith("marketing_cta_clicked", {
      placement: "pricing_card",
      destination: "register",
      mode: "clases",
    });
    vista.unmount();
  });
  it("con modo citas queda fijo: por profesional, sin talleres y con ?modo=citas", () => {
    const vista = montarFijo("citas");
    expect(vista.find(".precios-selector").exists()).toBe(false);
    expect(vista.get(".precios-modelo").text()).toBe(
      "Citas 1 a 1 · Por profesionales activos",
    );
    expect(
      vista.findAll(".precio-importe strong").map((n) => n.text()),
    ).toEqual(["$269", "$495", "$630"]);
    expect(vista.findAll(".precio-contexto").map((n) => n.text())).toEqual([
      "Citas 1 a 1",
      "Citas 1 a 1",
      "Citas 1 a 1",
    ]);
    expect(vista.find(".precios-contacto").exists()).toBe(false);
    expect(vista.text()).not.toMatch(
      /alumnos activos|talleres|en preparación/i,
    );
    expect(vista.text()).not.toContain("$9 + IVA");
    expect(vista.text()).toContain("$269 + $226 = $495");
    // Quién cuenta, en palabras de citas (como su pregunta frecuente).
    expect(vista.text()).toContain("al menos una cita no cancelada en el mes");
    // Beneficios con marca SVG, sin «✓» de texto.
    expect(vista.text()).not.toContain("✓");
    expect(vista.findAll(".precio-tarjeta li svg.precio-check").length).toBe(
      vista.findAll(".precio-tarjeta li").length,
    );
    expect(vista.get(".tu-nota-mexico").exists()).toBe(true);
    for (const cta of vista.findAll(".precio-cta")) {
      expect(JSON.parse(cta.attributes("data-to")!)).toEqual({
        name: "registro",
        query: { modo: "citas" },
      });
    }
    vista.unmount();
  });
  it("cada tarjeta lleva el borde de arriba rosa y su botón en azul", () => {
    for (const vista of [montar(), montarFijo("clases"), montarFijo("citas")]) {
      const botones = vista.findAll(".precio-tarjeta .precio-cta");
      expect(botones).toHaveLength(3);
      for (const boton of botones) {
        expect(boton.classes()).toEqual(
          expect.arrayContaining(["tu-btn-primario", "tu-btn-azul"]),
        );
      }
      vista.unmount();
    }
    // jsdom no aplica el CSS con scope: se revisa la regla del componente.
    expect(fuente).toMatch(
      /\.precio-tarjeta \{[^}]*border-top: 3px solid var\(--marketing-cta\);/,
    );
    expect(fuente).not.toMatch(
      /\.precio-tarjeta \{[^}]*border-top: 3px solid var\(--primario\);/,
    );
  });
});
