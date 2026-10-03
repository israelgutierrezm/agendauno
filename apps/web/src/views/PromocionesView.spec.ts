import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import PromocionesView from "./PromocionesView.vue";

/*
| Promociones con el patrón de los listados: indicadores, búsqueda por código y
| filtro de vigentes; el estado con punto.
*/

const mocks = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));

const promo = (id: string, codigo: string, vigente: boolean, usos: number) => ({
  id,
  codigo,
  descripcion: null,
  tipo: "porcentaje",
  valor: 1500,
  monto_minimo_minor: null,
  usos_maximos: null,
  usos,
  vence_en: null,
  activa: vigente,
  vigente,
});

beforeEach(() => {
  vi.clearAllMocks();
  mocks.get.mockResolvedValue({
    data: {
      data: [promo("1", "VERANO", true, 4), promo("2", "BUENFIN", false, 9)],
    },
  });
});

describe("promociones", () => {
  it("suma los usos y filtra por código y por vigencia", async () => {
    const w = mount(PromocionesView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    expect(w.text()).toContain("Veces usadas");
    expect(w.text()).toContain("13");
    expect(w.findAll('[data-prueba="promo"]')).toHaveLength(2);

    await w.get('[data-prueba="buscar-promo"]').setValue("ver");
    expect(w.findAll('[data-prueba="promo"]')).toHaveLength(1);

    await w.get('[data-prueba="buscar-promo"]').setValue("");
    await w
      .findAll("button")
      .find((b) => b.text() === "No vigentes")!
      .trigger("click");
    const filas = w.findAll('[data-prueba="promo"]');
    expect(filas).toHaveLength(1);
    expect(filas[0]!.text()).toContain("BUENFIN");
  });
});
