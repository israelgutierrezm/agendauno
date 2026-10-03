import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import CobranzaView from "./CobranzaView.vue";

/*
| Cobros con el patrón de los listados: indicadores de cada vista, y en movimientos
| búsqueda por cliente y filtro por estado.
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  ruta: { query: { vista: "movimientos" } as Record<string, string> },
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
vi.mock("vue-router", () => ({ useRoute: () => mocks.ruta }));

const pago = (id: string, persona: string, estado: string) => ({
  id,
  fecha: "2026-10-01T18:00:00Z",
  persona,
  monto_minor: 10000,
  moneda: "MXN",
  estado,
  proveedor: null,
  metodo: "efectivo",
  reembolsado_minor: estado === "reembolsado" ? 10000 : 0,
  reembolsable_minor: estado === "reembolsado" ? 0 : 10000,
});

beforeEach(() => {
  vi.clearAllMocks();
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/pagos")
          ? [
              pago("1", "Ana López", "aprobado"),
              pago("2", "Bea Ruiz", "reembolsado"),
            ]
          : [],
      },
    }),
  );
});

describe("cobros: movimientos", () => {
  it("resume lo cobrado y filtra por cliente y por estado", async () => {
    const w = mount(CobranzaView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    expect(w.text()).toContain("Cobros aprobados");
    expect(w.text()).toContain("$100.00");
    const filas = () => w.findAll("tbody > tr").map((f) => f.text());
    expect(filas()).toHaveLength(2);

    await w.get('[data-prueba="buscar-pago"]').setValue("bea");
    expect(filas()).toHaveLength(1);
    expect(filas()[0]).toContain("Bea Ruiz");

    await w.get('[data-prueba="buscar-pago"]').setValue("");
    await w
      .findAll("button")
      .find((b) => b.text() === "Aprobados")!
      .trigger("click");
    expect(filas()).toHaveLength(1);
    expect(filas()[0]).toContain("Ana López");
  });
});
