import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import type { PlanCitas } from "@/lib/suscripcion";
import PlanCitasRenta from "./PlanCitasRenta.vue";

/*
| Plan de un negocio de citas (ADR 0107): los tres niveles con su precio en dólares
| (y lo aproximado en pesos), mensual o anual a 10 meses; cambiarlo manda el nivel,
| los profesionales y la periodicidad, y avisa si aplica hoy o el siguiente periodo.
*/

const api = vi.hoisted(() => ({ put: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));

function plan(cambios: Partial<PlanCitas> = {}): PlanCitas {
  return {
    nivel: "premium",
    profesionales: 3,
    periodicidad: "mensual",
    elegido: true,
    en_prueba: false,
    cubierto_hasta: "2026-10-31",
    siguiente: null,
    profesionales_actuales: 2,
    limite_profesionales: 3,
    max_profesionales: 20,
    meses_anual: 10,
    moneda_tarifa: "USD",
    moneda_cobro: "MXN",
    iva_porcentaje: 16,
    tipo_cambio: { valor: "20.0000", fecha: "2026-10-01", fuente: "manual" },
    precios: {
      individual: { "1": 900 },
      premium: { "2": 2400, "3": 2800, "4": 3300 },
      pro: { "2": 3300, "3": 5000, "4": 5000 },
    },
    ...cambios,
  };
}

function montar(p: PlanCitas = plan()) {
  return mount(PlanCitasRenta, {
    props: {
      plan: p,
      base: "/api/v1/app/demo",
      ventas: { correo: "ventas@agendauno.mx", whatsapp: "5215512345678" },
    },
    global: { plugins: [i18n] },
  });
}

describe("PlanCitasRenta", () => {
  it("muestra el plan contratado y hasta cuándo está pagado", () => {
    const w = montar();
    expect(w.text()).toContain("Premium · 3 profesionales");
    expect(w.text()).toContain("Pagado hasta el 31 de octubre de 2026");
    expect(w.text()).toContain("2 de 3 profesionales en uso");
    expect(w.find("[data-prueba='selector-plan']").exists()).toBe(false);
  });

  it("al cambiar, compara los niveles en dólares y en anual cobra 10 meses", async () => {
    const w = montar();
    await w.get("[data-prueba='cambiar-plan']").trigger("click");
    const precio = (nivel: string) =>
      w.get(`[data-prueba='nivel-${nivel}'] .text-2xl`).text();
    expect(precio("individual")).toContain("9");
    expect(precio("premium")).toContain("28");
    expect(precio("pro")).toContain("50");
    // A 20 pesos por dólar.
    expect(w.get("[data-prueba='nivel-premium']").text()).toContain("$560");
    // Individual no sirve con 2 profesionales; el actual no se vuelve a elegir.
    expect(
      w.get("[data-prueba='nivel-individual'] button").attributes("disabled"),
    ).toBeDefined();
    expect(
      w.get("[data-prueba='nivel-premium'] button").attributes("disabled"),
    ).toBeDefined();

    const anual = w
      .findAll(".tu-segmentado button")
      .find((b) => b.text() === "Anual")!;
    await anual.trigger("click");
    expect(precio("premium")).toContain("280");
    expect(w.text()).toContain("2 de cortesía");
    expect(w.text()).toContain("ventas@agendauno.mx");
  });

  it("subir a Pro manda el plan y avisa lo que se cobró por los días que faltan", async () => {
    api.put.mockResolvedValueOnce({
      data: {
        data: {
          aplica: "ahora",
          ajuste: { monto_minor: 29858, moneda: "MXN", estado: "pendiente" },
        },
      },
    });
    const w = montar();
    await w.get("[data-prueba='cambiar-plan']").trigger("click");
    await w.get("[data-prueba='nivel-pro'] button").trigger("click");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/renta/plan", {
      nivel: "pro",
      profesionales: 3,
      periodicidad: "mensual",
    });
    expect(w.emitted("cambiado")?.[0]?.[0]).toContain("$298.58");
  });

  it("no deja contratar menos profesionales de los que ya tiene", async () => {
    const w = montar(plan({ profesionales_actuales: 3 }));
    await w.get("[data-prueba='cambiar-plan']").trigger("click");
    const menos = w.get("button[aria-label='Uno menos']");
    expect(menos.attributes("disabled")).toBeDefined();
    expect(w.get("[data-prueba='profesionales']").text()).toBe("3");
  });
});
