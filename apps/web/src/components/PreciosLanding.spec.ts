import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import PreciosLanding from "./PreciosLanding.vue";
import {
  bandasEstudios,
  conIva,
  ejemplosCitas,
  pesos,
} from "@/marketing/precios";

vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
const montar = () =>
  mount(PreciosLanding, {
    global: { stubs: { RouterLink: { template: "<a><slot /></a>" } } },
  });

describe("precios públicos", () => {
  it("muestra el precio sin IVA y la leyenda debajo, con prueba sin tarjeta", () => {
    const vista = montar();
    expect(vista.findAll("article")).toHaveLength(3);
    expect(vista.get('button[aria-pressed="true"]').text()).toBe(
      "Estudios y academias",
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
  it("cambia a citas y explica medio tiempo y cargos por actividad grupal", async () => {
    const vista = montar();
    await vista.findAll("button")[1]!.trigger("click");
    expect(vista.get('button[aria-pressed="true"]').text()).toBe(
      "Citas por profesional",
    );
    expect(vista.get(".precios-intro").text()).toContain("$134.50");
    expect(vista.get(".precios-intro").text()).toContain("medio tiempo");
    expect(vista.get(".precio-importe strong").text()).toBe("$269");
    expect(vista.findAll(".precio-importe strong")[1]!.text()).toBe("$495");
    expect(vista.findAll(".precio-importe strong")[2]!.text()).toBe("$630");
    expect(vista.findAll(".precio-impuestos").map((n) => n.text())).toEqual([
      "+ IVA",
      "+ IVA",
      "+ IVA",
    ]);
    expect(vista.text()).toContain("$9 + IVA");
    expect(vista.text()).toContain(
      "no se aplica a los clientes atendidos solo por cita",
    );
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
    expect(conIva(13450)).toBe(15602);
    expect(conIva(33900)).toBe(39324);
    expect(conIva(0)).toBe(0);
    expect(pesos(15602)).toBe("$156.02");
  });
});
