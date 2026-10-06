import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import RegionNegocioView from "./RegionNegocioView.vue";

/*
| Moneda y zona horaria del negocio (ADR 0099): una sola moneda, que se elige antes de
| cobrar (luego queda fija), y la zona horaria de reportes y cortes. Dice qué funciona
| con la moneda elegida: pasarelas en línea y facturación, solo en pesos mexicanos.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
const cargarYo = vi.hoisted(() => vi.fn());
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    moneda: "MXN",
    puede: () => true,
    cargarYo,
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

function region(cambios: Record<string, unknown> = {}) {
  return {
    moneda: "MXN",
    zona_horaria: "America/Mexico_City",
    monedas: [
      { codigo: "MXN", nombre: "Peso mexicano" },
      { codigo: "USD", nombre: "Dólar estadounidense" },
    ],
    puede_cambiar_moneda: true,
    pasarelas: { disponibles: true, motivo: null },
    facturacion: { disponible: true, motivo: null },
    ...cambios,
  };
}

function montar() {
  return mount(RegionNegocioView, { global: { plugins: [i18n] } });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("moneda y zona horaria del negocio", () => {
  it("muestra la moneda y la zona, y qué funciona en pesos", async () => {
    api.get.mockResolvedValue({ data: { data: region() } });
    const w = montar();
    await flushPromises();

    expect(
      (w.get('[data-prueba="moneda-negocio"]').element as HTMLSelectElement)
        .value,
    ).toBe("MXN");
    expect(w.get('[data-prueba="zona-negocio"]').text()).toContain(
      "Centro de México (Ciudad de México)",
    );
    expect(w.get('[data-prueba="region-avisos"]').text()).toContain(
      "Disponible",
    );

    // Al elegir otra moneda, ya dice que el cobro en línea y la factura no aplican.
    await w.get('[data-prueba="moneda-negocio"]').setValue("USD");
    const avisos = w.get('[data-prueba="region-avisos"]').text();
    expect(avisos).toContain("Solo con pesos mexicanos (MXN)");
    expect(avisos).toContain("y para negocios en México");
  });

  it("guarda solo lo que cambió y actualiza la sesión", async () => {
    api.get.mockResolvedValue({ data: { data: region() } });
    api.put.mockResolvedValue({
      data: { data: region({ zona_horaria: "America/Tijuana" }) },
    });
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="zona-negocio"]').setValue("America/Tijuana");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/negocio/region", {
      zona_horaria: "America/Tijuana",
    });
    expect(cargarYo).toHaveBeenCalled();
  });

  it("con cobros registrados, la moneda queda fija y lo dice", async () => {
    api.get.mockResolvedValue({
      data: { data: region({ puede_cambiar_moneda: false }) },
    });
    const w = montar();
    await flushPromises();

    expect(
      w.get('[data-prueba="moneda-negocio"]').attributes("disabled"),
    ).toBeDefined();
    expect(w.get('[data-prueba="moneda-bloqueada"]').text()).toContain(
      "Ya hay cobros registrados en MXN",
    );
  });
});
