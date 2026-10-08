import { flushPromises, mount } from "@vue/test-utils";
import axios from "axios";
import { describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import type { PlanCitas } from "@/lib/suscripcion";
import PlanEstudioPlataforma from "./PlanEstudioPlataforma.vue";

/*
| El plan de un negocio desde su ficha en el superadmin (ADR 0107): se ve lo
| contratado y se cambia; quitar «Cobrar la diferencia» sube sin cobrar (cortesía).
*/

vi.mock("axios", async (original) => {
  const real = await original<typeof import("axios")>();
  return { default: { ...real.default, put: vi.fn() } };
});

const plan: PlanCitas = {
  nivel: "premium",
  profesionales: 3,
  periodicidad: "mensual",
  elegido: true,
  en_prueba: false,
  cubierto_hasta: "2026-10-31",
  siguiente: null,
  profesionales_actuales: 3,
  limite_profesionales: 3,
  max_profesionales: 20,
  meses_anual: 10,
  moneda_tarifa: "USD",
  moneda_cobro: "MXN",
  iva_porcentaje: 16,
  tipo_cambio: null,
  precios: {
    individual: { "1": 900 },
    premium: { "2": 2400, "3": 2800, "4": 3300 },
    pro: { "2": 3300, "3": 5000, "4": 5000 },
  },
};

describe("PlanEstudioPlataforma", () => {
  it("muestra el plan y lo cambia sin cobrar la diferencia si así se pide", async () => {
    vi.mocked(axios.put).mockResolvedValueOnce({
      data: { data: { aplica: "ahora", ajuste: null } },
    });
    const w = mount(PlanEstudioPlataforma, {
      props: { apiUrl: "http://api", token: "t", slug: "barberia", plan },
      global: { plugins: [i18n] },
    });

    expect(w.text()).toContain("Premium · 3 profesionales");
    expect(w.text()).toContain("Pagado hasta el");

    await w.get("select").setValue("pro");
    await w.get("input[type=checkbox]").setValue(false);
    expect(w.text()).toMatch(/USD\s50\.00 al mes/);
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(axios.put).toHaveBeenCalledWith(
      "http://api/api/v1/plataforma/estudios/barberia/plan",
      {
        nivel: "pro",
        profesionales: 3,
        periodicidad: "mensual",
        cobrar_diferencia: false,
      },
      expect.anything(),
    );
    expect(w.emitted("cambiado")).toHaveLength(1);
    expect(w.text()).toContain("Listo, tu plan cambió.");
  });
});
