import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import PosView from "./PosView.vue";

/*
| Mostrador: se vende lo que hay en la sucursal y las ventas recientes muestran la
| forma de pago a la vista (no el valor interno), las anuladas marcadas, y una venta
| con error se corrige por su propia ruta (ADR 0089).
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  put: vi.fn(),
  post: vi.fn(),
  confirmar: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, put: mocks.put, post: mocks.post },
  mensajeDeError: () => "Error",
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: mocks.confirmar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const VENTAS = [
  {
    id: "v1",
    sucursal: "Roma Norte",
    total_minor: 56000,
    moneda: "MXN",
    metodo_pago: "efectivo",
    creado_en: "2026-10-01T18:00:00Z",
    lineas: [{ articulo: "Pomada mate", cantidad: 2 }],
    corregible: true,
    anulable: true,
  },
  {
    id: "v2",
    sucursal: "Roma Norte",
    total_minor: 23000,
    moneda: "MXN",
    metodo_pago: "tarjeta",
    creado_en: "2026-10-01T17:00:00Z",
    lineas: [{ articulo: "Cera brillante", cantidad: 1 }],
    anulada_en: "2026-10-01T17:10:00Z",
    motivo_anulacion: "Se registró dos veces",
    corregible: false,
    anulable: false,
  },
];

const ARTICULOS = [
  {
    id: "a1",
    nombre: "Pomada mate",
    sku: "PM-1",
    precio_minor: 28000,
    moneda: "MXN",
    activo: true,
    stock_total: 2,
    existencias: [{ sucursal_id: "s1", sucursal: "Roma Norte", stock: 2 }],
  },
];

beforeEach(() => {
  vi.clearAllMocks();
  mocks.confirmar.mockResolvedValue(true);
  mocks.put.mockResolvedValue({ data: {} });
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/pos/ventas")
          ? VENTAS
          : url.endsWith("/sucursales")
            ? [{ id: "s1", nombre: "Roma Norte" }]
            : url.endsWith("/articulos")
              ? ARTICULOS
              : [],
      },
    }),
  );
});

describe("ventas recientes del mostrador", () => {
  it("muestra la forma de pago con su nombre y marca las anuladas", async () => {
    const w = mount(PosView, { global: { plugins: [i18n] } });
    await flushPromises();

    const filas = w.findAll("tbody > tr");
    expect(filas[0]!.text()).toContain("Efectivo");
    expect(filas[0]!.text()).toContain("2 × Pomada mate");
    expect(w.get('[data-prueba="venta-anulada"]').text()).toBe("Anulada");
    expect(w.findAll('[data-prueba="corregir-venta"]')).toHaveLength(1);
  });

  it("corrige la forma de pago por la ruta de la venta", async () => {
    const w = mount(PosView, { global: { plugins: [i18n] } });
    await flushPromises();

    await w.get('[data-prueba="corregir-venta"]').trigger("click");
    await w.get('[data-prueba="corregir-pago"]').trigger("click");
    await w.get('[data-prueba="venta-actual"]'); // la venta y su corrección conviven
    const metodo = w
      .findAll("select")
      .find((s) => s.text().includes("Transferencia"))!;
    await metodo.setValue("tarjeta");
    await w
      .findAll("button")
      .find((b) => b.text() === "Guardar forma de pago")!
      .trigger("click");
    await flushPromises();

    expect(mocks.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/pos/ventas/v1/metodo",
      { metodo: "tarjeta" },
    );
  });

  it("no deja vender más de lo que hay en la sucursal y cobra con la forma elegida", async () => {
    mocks.post.mockResolvedValue({ data: {} });
    const w = mount(PosView, { global: { plugins: [i18n] } });
    await flushPromises();

    const producto = w.get('[data-prueba="producto"]');
    await producto.trigger("click");
    await producto.trigger("click");
    // Solo hay 2: el tercero ya no se puede agregar.
    expect(producto.attributes("disabled")).toBeDefined();
    await w
      .findAll("button")
      .find((b) => b.text() === "Tarjeta")!
      .trigger("click");
    await w.get('[data-prueba="cobrar"]').trigger("click");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith("/api/v1/app/demo/pos/ventas", {
      sucursal_id: "s1",
      metodo_pago: "tarjeta",
      items: [{ articulo_id: "a1", cantidad: 2 }],
    });
  });
});
