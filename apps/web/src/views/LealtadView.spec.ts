import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import LealtadView from "./LealtadView.vue";

/*
| Lealtad: la ayuda de «puntos por unidad de moneda» habla de la moneda del negocio,
| no de pesos.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
const sesion = vi.hoisted(() => ({
  slug: "demo",
  puede: () => true,
  moneda: "MXN",
  pais: "MX",
}));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: String }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn() }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/programa")
          ? { activa: true, puntos_por_asistencia: 10, puntos_por_moneda: 1 }
          : [],
      },
    }),
  );
});

async function ayuda(moneda: string, pais: string): Promise<string> {
  sesion.moneda = moneda;
  sesion.pais = pais;
  const w = mount(LealtadView, {
    global: { plugins: [i18n], stubs: { BuscarPersona: true } },
  });
  await flushPromises();
  return w.get('[data-prueba="ayuda-por-moneda"]').text().replace(/\s/g, " ");
}

describe("puntos por unidad de moneda", () => {
  it("en pesos mexicanos, como siempre", async () => {
    expect(await ayuda("MXN", "MX")).toBe(
      "Ej. 1 = un punto por cada $1 pagado.",
    );
  });

  it("en otra moneda, con su símbolo", async () => {
    expect(await ayuda("EUR", "ES")).toBe(
      "Ej. 1 = un punto por cada 1 € pagado.",
    );
  });
});
