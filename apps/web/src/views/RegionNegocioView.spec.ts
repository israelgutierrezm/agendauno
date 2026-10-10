import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import RegionNegocioView from "./RegionNegocioView.vue";

/*
| País, moneda y zona horaria del negocio (ADR 0099 y 0103): el país (de él sale la
| lada), una sola moneda, que se elige antes de cobrar (luego queda fija), y la zona
| horaria de reportes y cortes. Dice qué funciona con lo elegido: pasarelas en línea
| solo en pesos mexicanos, y facturación solo en pesos y en México.
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
    pais: "MX",
    lada: "52",
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
  return mount(RegionNegocioView, {
    attachTo: document.body,
    global: { plugins: [i18n] },
  });
}
type Vista = ReturnType<typeof montar>;

// Elige en un selector con buscador: escribe y toma la primera coincidencia.
async function elegir(w: Vista, prueba: string, texto: string): Promise<void> {
  const campo = w.get(`[data-prueba="${prueba}"]`);
  await campo.trigger("click");
  await campo.setValue(texto);
  await campo.trigger("keydown", { key: "Enter" });
}
function valor(w: Vista, prueba: string): string {
  return (w.get(`[data-prueba="${prueba}"]`).element as HTMLInputElement).value;
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("país, moneda y zona horaria del negocio", () => {
  it("muestra el país, la moneda y la zona, y qué funciona en pesos", async () => {
    api.get.mockResolvedValue({ data: { data: region() } });
    const w = montar();
    await flushPromises();

    expect(
      (w.get('[data-prueba="moneda-negocio"]').element as HTMLSelectElement)
        .value,
    ).toBe("MXN");
    expect(valor(w, "pais-negocio")).toBe("México");
    expect(valor(w, "zona-negocio")).toBe(
      "Centro de México (Ciudad de México)",
    );
    expect(w.find('[data-prueba="fuera-de-mexico"]').exists()).toBe(false);
    expect(w.get('[data-prueba="region-avisos"]').text()).toContain(
      "Disponible",
    );

    // Al elegir otra moneda, ya dice que el cobro en línea y la factura no aplican.
    await w.get('[data-prueba="moneda-negocio"]').setValue("USD");
    const avisos = w.get('[data-prueba="region-avisos"]').text();
    expect(avisos).toContain("Solo con pesos mexicanos (MXN)");
    expect(avisos).toContain("y para negocios en México");
    w.unmount();
  });

  it("guarda solo lo que cambió y actualiza la sesión", async () => {
    api.get.mockResolvedValue({ data: { data: region() } });
    api.put.mockResolvedValue({
      data: { data: region({ zona_horaria: "America/Tijuana" }) },
    });
    const w = montar();
    await flushPromises();

    await elegir(w, "zona-negocio", "tijuana");
    expect(valor(w, "zona-negocio")).toBe("Noroeste (Tijuana)");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/negocio/region", {
      zona_horaria: "America/Tijuana",
    });
    expect(cargarYo).toHaveBeenCalled();
    w.unmount();
  });

  it("cambia el país: avisa que fuera de México no hay factura y propone sus zonas", async () => {
    api.get.mockResolvedValue({ data: { data: region() } });
    api.put.mockResolvedValue({
      data: {
        data: region({
          pais: "CO",
          lada: "57",
          facturacion: { disponible: false, motivo: "x" },
        }),
      },
    });
    const w = montar();
    await flushPromises();

    await elegir(w, "pais-negocio", "colombia");
    expect(valor(w, "pais-negocio")).toBe("Colombia");
    expect(w.get('[data-prueba="fuera-de-mexico"]').text()).toContain(
      "Fuera de México no hay facturación a tus clientes",
    );
    // En pesos sigue el cobro en línea; la factura ya no (no está en México).
    const avisos = w.get('[data-prueba="region-avisos"]').text();
    expect(avisos).toContain("Disponible");
    expect(avisos).toContain("y para negocios en México");

    // Las zonas de Colombia van primero.
    await w.get('[data-prueba="zona-negocio"]').trigger("click");
    expect(w.findAll('[role="option"]')[0].text()).toContain(
      "Colombia (Bogotá)",
    );
    await w.get('[data-prueba="zona-negocio"]').trigger("keydown", {
      key: "Escape",
    });

    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/negocio/region", {
      pais: "CO",
    });
    expect(cargarYo).toHaveBeenCalled();
    w.unmount();
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

  it("pasada la prueba, el país define el cobro: queda fijo y dice por qué", async () => {
    api.get.mockResolvedValue({
      data: {
        data: region({
          puede_cambiar_pais: false,
          motivo_pais: "El país ya no se puede cambiar desde aquí.",
        }),
      },
    });
    const w = montar();
    await flushPromises();

    expect(w.get("#rg-pais").attributes("disabled")).toBeDefined();
    expect(w.get('[data-prueba="pais-bloqueado"]').text()).toBe(
      "El país ya no se puede cambiar desde aquí.",
    );
    w.unmount();
  });
});
