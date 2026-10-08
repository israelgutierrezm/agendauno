import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import PreciosLanding from "./PreciosLanding.vue";
import fuente from "./PreciosLanding.vue?raw";
import { trackEvent } from "@/lib/analytics";
import type { Modo } from "@/marketing/modalidades";
import {
  bandasEstudios,
  dolares,
  nivelesCitas,
  preciosCitas,
} from "@/marketing/precios";

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

describe("precios públicos (ADR 0107)", () => {
  it("clases: en dólares más impuestos, con la nota de pesos en México y prueba sin tarjeta", () => {
    const vista = montar();
    expect(vista.findAll("article")).toHaveLength(3);
    expect(vista.get('button[aria-pressed="true"] strong').text()).toBe(
      "Por alumnos activos",
    );
    expect(vista.get(".precio-importe strong").text()).toBe("$21");
    expect(vista.get(".precio-importe").text()).toContain("USD / mes");
    expect(vista.get(".precio-impuestos").text()).toBe("+ impuestos");
    expect(vista.text()).toContain(
      "En México se cobran en pesos al tipo de cambio del día del cobro, más IVA.",
    );
    expect(vista.get("article").text()).toContain("Probar 30 días gratis");
    expect(vista.get("article").text()).toContain("Sin tarjeta");
    expect(vista.findAll("tbody tr")).toHaveLength(9);
    expect(vista.get(".precios-contacto a").attributes("href")).toContain(
      "mailto:ventas@agendauno.mx",
    );
    expect(vista.get(".precios-contacto").text()).toContain(
      "Más de 1,000 alumnos activos",
    );
    expect(vista.text()).toContain(
      "Sin alumnos activos, la renta por uso es $0",
    );
    expect(vista.text()).not.toMatch(/MXN|\+ IVA/);
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
    expect(modelos[1]!.text()).toContain("Por plan y profesionales");
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
  it("citas: Individual, Premium y Pro; el anual cuesta 10 meses", async () => {
    const vista = montar();
    await vista.findAll(".precios-selector button")[1]!.trigger("click");
    expect(vista.findAll(".precio-tarjeta h4").map((n) => n.text())).toEqual([
      "Individual",
      "Premium",
      "Pro",
    ]);
    expect(
      vista.findAll(".precio-importe strong").map((n) => n.text()),
    ).toEqual(["$9", "$24", "$33"]);
    expect(vista.get(".precios-intro").text()).toContain("$9 USD al mes");
    // Funciones que separan los niveles.
    const tarjetas = vista.findAll(".precio-tarjeta");
    expect(tarjetas[0]!.text()).toContain("Tu página con dirección propia");
    expect(tarjetas[0]!.text()).toContain("Cobro al agendar en línea*");
    expect(tarjetas[1]!.text()).toContain("Equipo, roles y varias sucursales");
    expect(tarjetas[2]!.text()).toContain("Facturación electrónica*");

    const anual = vista
      .findAll(".precios-periodo button")
      .find((b) => b.text().startsWith("Anual"))!;
    await anual.trigger("click");
    expect(
      vista.findAll(".precio-importe strong").map((n) => n.text()),
    ).toEqual(["$90", "$240", "$330"]);
    expect(vista.get(".precio-importe").text()).toContain("USD / año");
    expect(vista.get(".precios-contacto").text()).toContain(
      "Más de 20 profesionales",
    );
    expect(vista.text()).not.toMatch(/talleres|reservas grupales/i);
    vista.unmount();
  });
  it("conserva los precios publicados (ReservaClase y AgendaPro −15 %, redondeados hacia abajo)", () => {
    expect(bandasEstudios.map((b) => b.subtotal)).toEqual([
      2100, 3000, 3900, 4800, 6800, 8400, 11100, 16400, 29700,
    ]);
    expect(nivelesCitas.map((n) => n.desde)).toEqual([900, 2400, 3300]);
    expect(preciosCitas).toHaveLength(19);
    expect(preciosCitas[0]).toEqual({
      profesionales: 2,
      premium: 2400,
      pro: 3300,
    });
    expect(preciosCitas[18]).toEqual({
      profesionales: 20,
      premium: 10100,
      pro: 17700,
    });
    expect(dolares(2100)).toBe("$21");
    expect(dolares(1550)).toBe("$15.50");
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
    expect(vista.get(".precio-importe strong").text()).toBe("$21");
    expect(vista.findAll("tbody tr")).toHaveLength(9);
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
  it("con modo citas queda fijo: por plan y profesionales, con ?modo=citas y la tabla de 2 a 20", () => {
    const vista = montarFijo("citas");
    expect(vista.find(".precios-selector").exists()).toBe(false);
    expect(vista.get(".precios-modelo").text()).toBe(
      "Citas 1 a 1 · Por plan y profesionales",
    );
    expect(vista.findAll(".precio-contexto").map((n) => n.text())).toEqual([
      "Citas 1 a 1",
      "Citas 1 a 1",
      "Citas 1 a 1",
    ]);
    // Individual + de 2 a 20 profesionales.
    expect(vista.findAll("tbody tr")).toHaveLength(20);
    expect(vista.text()).not.toMatch(
      /alumnos activos|talleres|en preparación/i,
    );
    // Beneficios con marca SVG, sin «✓» de texto.
    expect(vista.text()).not.toContain("✓");
    expect(vista.findAll(".precio-tarjeta li svg.precio-check").length).toBe(
      vista.findAll(".precio-tarjeta li").length,
    );
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
