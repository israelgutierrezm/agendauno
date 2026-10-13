import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import InventarioView from "./InventarioView.vue";

/*
| Inventario con el patrón de los listados: el estado de cada producto (con stock,
| stock bajo, sin stock) lo calcula el API con el umbral del negocio; la búsqueda, los
| filtros y las páginas van al servidor. Las entradas y los ajustes (con su motivo)
| se registran en un diálogo, los errores se marcan dentro de él junto a su campo, y
| cada producto tiene su historial.
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  put: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
  categorias: [] as {
    id: string;
    nombre: string;
    orden: number;
    productos: number;
  }[],
}));
vi.mock("@/lib/api", () => ({
  api: {
    get: mocks.get,
    put: mocks.put,
    post: mocks.post,
    delete: mocks.delete,
  },
  mensajeDeError: () => "Error",
  erroresPorCampo: (e: unknown) =>
    (e as { campos?: Record<string, string> })?.campos ?? {},
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(() => true) }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: () => true,
    zonaHoraria: "America/Mexico_City",
  }),
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
  mocks.categorias = [];
  mocks.post.mockResolvedValue({ data: { data: { id: "nuevo" } } });
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/categorias-articulo")
        ? { data: mocks.categorias }
        : url.endsWith("/articulos")
          ? {
              data: [
                articulo("a1", "Shampoo", 12, "con_stock"),
                articulo("a2", "Aceite para barba", 2, "bajo"),
                articulo("a3", "Peine", 0, "sin_stock"),
                articulo("a4", "Cera vieja", 0, "sin_stock", false),
              ],
              meta: {
                stock_bajo: 3,
                total: 4,
                page: 1,
                per_page: 25,
                ultima_pagina: 1,
                total_articulos: 4,
                resumen: { productos: 3, con_stock: 1, bajo: 1, sin_stock: 1 },
              },
            }
          : { data: [{ id: "s1", nombre: "Roma Norte" }] },
    }),
  );
});

describe("inventario", () => {
  it("los indicadores cuentan todo y la búsqueda y los filtros van al servidor", async () => {
    const w = mount(InventarioView, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(w.text()).toContain("Productos a la venta");
    expect(w.get('[data-prueba="indicador-productos"]').text()).toContain("3");
    expect(w.findAll('tr[data-prueba="producto"]')).toHaveLength(4);
    expect(
      w.findAll("[data-estado]").map((p) => p.attributes("data-estado")),
    ).toEqual(["con_stock", "bajo", "sin_stock", "sin_stock"]);

    await w.get('[data-prueba="filtro-stock"]').setValue("bajo");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/app/demo/articulos", {
      params: expect.objectContaining({ page: 1, stock: "bajo" }),
    });

    await w.get('[data-prueba="buscar"]').setValue("sku-a3");
    await new Promise((r) => setTimeout(r, 350));
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/app/demo/articulos", {
      params: expect.objectContaining({ q: "sku-a3", stock: "bajo" }),
    });
  });

  it("un producto nuevo se guarda con sus extras y su precio (ADR 0139)", async () => {
    const w = mount(InventarioView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    await w.get('[data-prueba="nuevo-producto"]').trigger("click");
    await w.get("#iv-nombre").setValue("Proteína preparada");
    await w.get("#iv-precio").setValue("60");
    await w.get('[data-prueba="agregar-extra"]').trigger("click");
    const [nombre, precio] = w.findAll('[data-prueba="extras-producto"] input');
    await nombre!.setValue("Fresas");
    await precio!.setValue("15");
    await w.get("#iv-producto").trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith("/api/v1/app/demo/articulos", {
      nombre: "Proteína preparada",
      sku: null,
      precio_minor: 6000,
      categoria_id: null,
      extras: [{ id: undefined, nombre: "Fresas", precio_minor: 1500 }],
    });
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

  it("filtra por categoría y crea un producto en una, con su foto", async () => {
    mocks.categorias = [
      { id: "c1", nombre: "Cuidado", orden: 1, productos: 1 },
    ];
    mocks.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.endsWith("/categorias-articulo")
          ? { data: mocks.categorias }
          : url.endsWith("/articulos")
            ? {
                data: [
                  {
                    ...articulo("a1", "Shampoo", 12, "con_stock"),
                    categoria: { id: "c1", nombre: "Cuidado", orden: 1 },
                  },
                  articulo("a3", "Peine", 0, "sin_stock"),
                ],
              }
            : { data: [{ id: "s1", nombre: "Roma Norte" }] },
      }),
    );
    const w = mount(InventarioView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    expect(w.get('[data-prueba="categoria-producto"]').text()).toBe("Cuidado");
    await w.get('[data-prueba="filtro-categoria"]').setValue("-");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/app/demo/articulos", {
      params: expect.objectContaining({ categoria: "-" }),
    });

    // Con el filtro en una categoría, el nuevo nace en ella; la foto sube al crearlo.
    await w.get('[data-prueba="filtro-categoria"]').setValue("c1");
    await w.get('[data-prueba="nuevo-producto"]').trigger("click");
    expect(
      (w.get('[data-prueba="categoria"]').element as HTMLSelectElement).value,
    ).toBe("c1");
    await w.get("#iv-nombre").setValue("Acondicionador");
    await w.get("#iv-precio").setValue("80");
    const foto = new File(["x"], "foto.png", { type: "image/png" });
    const entrada = w.get('[data-prueba="foto-producto"] input[type="file"]');
    Object.defineProperty(entrada.element, "files", { value: [foto] });
    globalThis.URL.createObjectURL = vi.fn(() => "blob:foto");
    globalThis.URL.revokeObjectURL = vi.fn();
    await entrada.trigger("change");
    await w.get("#iv-producto").trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/articulos",
      expect.objectContaining({ nombre: "Acondicionador", categoria_id: "c1" }),
    );
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/articulos/nuevo/foto",
      expect.any(FormData),
    );
  });

  it("administra las categorías: agrega, renombra y quita", async () => {
    mocks.categorias = [
      { id: "c1", nombre: "Cuidado", orden: 1, productos: 2 },
    ];
    mocks.put.mockResolvedValue({ data: {} });
    mocks.delete.mockResolvedValue({});
    const w = mount(InventarioView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    await w.get('[data-prueba="categorias"]').trigger("click");
    expect(w.get('[data-prueba="lista-categorias"]').text()).toContain(
      "2 productos",
    );

    await w.get('[data-prueba="nueva-categoria"] input').setValue("Bebidas");
    await w.get('[data-prueba="nueva-categoria"]').trigger("submit");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/categorias-articulo",
      { nombre: "Bebidas" },
    );

    await w
      .get('[data-prueba="lista-categorias"] input')
      .setValue("Cuidado personal");
    await w
      .findAll('[data-prueba="lista-categorias"] button')
      .find((b) => b.text() === "Guardar")!
      .trigger("click");
    await flushPromises();
    expect(mocks.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/categorias-articulo/c1",
      { nombre: "Cuidado personal" },
    );

    await w.get('[data-prueba="quitar-categoria-c1"]').trigger("click");
    await flushPromises();
    expect(mocks.delete).toHaveBeenCalledWith(
      "/api/v1/app/demo/categorias-articulo/c1",
    );
  });

  it("un ajuste pide el motivo y lo manda con su detalle", async () => {
    const w = mount(InventarioView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    await w.findAll('[data-prueba="movimiento"]')[0]!.trigger("click");
    await w.get('[data-prueba="tipo-ajuste"]').trigger("click");
    await w.get('[data-prueba="cantidad"]').setValue("-2");
    const guardar = w.get('[data-prueba="guardar-movimiento"]');
    expect(guardar.attributes("disabled")).toBeDefined();

    await w.get('[data-prueba="motivo"]').setValue("merma");
    await w.get('[data-prueba="detalle"]').setValue("se rompieron 2");
    expect(guardar.attributes("disabled")).toBeUndefined();
    await w.get("#iv-movimiento").trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/articulos/a1/movimientos",
      {
        sucursal_id: "s1",
        tipo: "ajuste",
        cantidad: -2,
        motivo: "Merma o daño: se rompieron 2",
      },
    );
  });

  it("los errores al guardar quedan en el diálogo, junto a su campo", async () => {
    mocks.post.mockRejectedValueOnce({
      campos: { nombre: "Escribe el nombre.", "extras.0.nombre": "Falta." },
    });
    const w = mount(InventarioView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    await w.get('[data-prueba="nuevo-producto"]').trigger("click");
    await w.get("#iv-nombre").setValue("X");
    await w.get("#iv-precio").setValue("10");
    await w.get('[data-prueba="agregar-extra"]').trigger("click");
    await w.get('[data-prueba="agregar-extra"]').trigger("click");
    const nombres = w.findAll('[data-prueba="extras-producto"] input');
    // El primer extra va sin nombre (no se manda); el error del enviado es el segundo.
    await nombres[2]!.setValue("Fresas");
    await w.get("#iv-producto").trigger("submit");
    await flushPromises();

    expect(w.get('[data-prueba="error-producto"]').text()).toContain(
      "Revisa los campos",
    );
    expect(w.get("#iv-nombre").attributes("aria-invalid")).toBe("true");
    expect(w.text()).toContain("Escribe el nombre.");
    const extras = w.findAll('[data-prueba="extras-producto"] input');
    expect(extras[2]!.attributes("aria-invalid")).toBe("true");
    expect(extras[0]!.attributes("aria-invalid")).toBeUndefined();
    // El diálogo sigue abierto.
    expect(w.find("#iv-producto").exists()).toBe(true);
  });

  it("abre el historial de un producto con quién y por qué", async () => {
    const base = mocks.get.getMockImplementation()!;
    mocks.get.mockImplementation((url: string, config?: unknown) =>
      url.endsWith("/articulos/a1/movimientos")
        ? Promise.resolve({
            data: {
              data: [
                {
                  id: "m2",
                  tipo: "ajuste",
                  cantidad: -2,
                  motivo: "Merma o daño",
                  sucursal: "Roma Norte",
                  quien: "Ana",
                  de_venta: false,
                  creado_en: "2026-10-01T18:00:00Z",
                },
                {
                  id: "m1",
                  tipo: "salida",
                  cantidad: -1,
                  motivo: null,
                  sucursal: "Roma Norte",
                  quien: null,
                  de_venta: true,
                  creado_en: "2026-10-01T17:00:00Z",
                },
              ],
              meta: { total: 2, page: 1, per_page: 20, ultima_pagina: 1 },
            },
          })
        : base(url, config),
    );
    const w = mount(InventarioView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    await w.findAll('[data-prueba="historial"]')[0]!.trigger("click");
    await flushPromises();
    const filas = w.findAll('[data-prueba="movimiento-historial"]');
    expect(filas).toHaveLength(2);
    expect(filas[0]!.text()).toContain("Ajuste a mano");
    expect(filas[0]!.text()).toContain("Merma o daño");
    expect(filas[0]!.text()).toContain("Ana");
    expect(filas[1]!.text()).toContain("Venta en mostrador");

    await w.get('[data-prueba="historial-ajuste"]').trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/articulos/a1/movimientos",
      { params: { page: 1, tipo: "ajuste" } },
    );
  });
});
