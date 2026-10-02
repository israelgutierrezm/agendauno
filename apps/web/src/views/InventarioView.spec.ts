import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import InventarioView from "./InventarioView.vue";

/*
| Inventario con el patrón de los listados: el estado de cada producto (con stock,
| stock bajo, sin stock) lo calcula el API con el umbral del negocio; se filtra por
| él y se registran entradas o ajustes en un diálogo.
*/

const mocks = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, put: mocks.put, post: mocks.post },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const articulo = (
  id: string,
  nombre: string,
  stock: number,
  estado: string,
  activo = true,
) => ({
  id,
  nombre,
  sku: `SKU-${id}`,
  precio_minor: 25000,
  moneda: "MXN",
  activo,
  stock_total: stock,
  estado_stock: estado,
  existencias: [{ sucursal_id: "s1", sucursal: "Roma Norte", stock, estado }],
});

beforeEach(() => {
  vi.clearAllMocks();
  mocks.post.mockResolvedValue({ data: {} });
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/articulos")
        ? {
            data: [
              articulo("a1", "Shampoo", 12, "con_stock"),
              articulo("a2", "Aceite para barba", 2, "bajo"),
              articulo("a3", "Peine", 0, "sin_stock"),
              articulo("a4", "Cera vieja", 0, "sin_stock", false),
            ],
            meta: { stock_bajo: 3 },
          }
        : { data: [{ id: "s1", nombre: "Roma Norte" }] },
    }),
  );
});

describe("inventario", () => {
  it("cuenta el stock de lo que está a la venta y filtra por su estado", async () => {
    const w = mount(InventarioView, { global: { plugins: [i18n] } });
    await flushPromises();

    const kpis = w.text();
    expect(kpis).toContain("Productos a la venta");
    expect(w.findAll('[data-prueba="producto"]')).toHaveLength(4);
    expect(
      w.findAll("[data-estado]").map((p) => p.attributes("data-estado")),
    ).toEqual(["con_stock", "bajo", "sin_stock", "sin_stock"]);

    await w.get('[data-prueba="filtro-stock"]').setValue("bajo");
    const filas = w.findAll('[data-prueba="producto"]');
    expect(filas).toHaveLength(1);
    expect(filas[0]!.text()).toContain("Aceite para barba");
    expect(filas[0]!.text()).toContain("Stock bajo");

    await w.get('[data-prueba="filtro-stock"]').setValue("");
    await w.get('[data-prueba="buscar"]').setValue("sku-a3");
    expect(w.findAll('[data-prueba="producto"]')).toHaveLength(1);
  });

  it("registra una entrada de stock en la sucursal", async () => {
    const w = mount(InventarioView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    await w.findAll('[data-prueba="movimiento"]')[2]!.trigger("click");
    await w.get('[data-prueba="cantidad"]').setValue("6");
    await w.get("#iv-movimiento").trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/articulos/a3/movimientos",
      { sucursal_id: "s1", tipo: "entrada", cantidad: 6 },
    );
  });
});
