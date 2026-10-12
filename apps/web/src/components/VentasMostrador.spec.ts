import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import VentasMostrador from "./VentasMostrador.vue";

/*
| Las ventas del mostrador (ADR 0145): por páginas, filtradas por días del negocio y,
| en la ficha de una persona, solo las suyas (sin la columna «Venta a»).
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: () => true,
    moneda: "MXN",
    pais: "MX",
    zonaHoraria: "America/Mexico_City",
  }),
}));

const venta = (id: string, extra: Record<string, unknown> = {}) => ({
  id,
  sucursal: "Roma Norte",
  comprador: null,
  total_minor: 4500,
  moneda: "MXN",
  metodo_pago: "tarjeta",
  creado_en: "2026-09-30T18:00:00Z",
  lineas: [{ articulo: "Café americano", cantidad: 1 }],
  ...extra,
});

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockResolvedValue({
    data: {
      data: [venta("v1"), venta("v2")],
      meta: {
        total: 45,
        page: 1,
        per_page: 20,
        ultima_pagina: 3,
        resumen: { ventas: 44, vendido_minor: 198000, propinas_minor: 0 },
        hoy: { ventas: 2, vendido_minor: 9000, propinas_minor: 0 },
      },
    },
  });
});

const url = "/api/v1/app/demo/pos/ventas";

describe("ventas del mostrador", () => {
  it("pide por páginas, suma lo filtrado y avisa lo de hoy", async () => {
    const w = mount(VentasMostrador, {
      props: { sucursalId: "s1" },
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(url, {
      params: { page: 1, per_page: 20, sucursal_id: "s1" },
    });
    expect(w.get('[data-prueba="ventas-resumen"]').text()).toBe(
      "44 ventas · $1,980.00",
    );
    expect(w.emitted("hoy")?.[0]).toEqual([
      { ventas: 2, vendido_minor: 9000, propinas_minor: 0 },
    ]);

    // Página 2.
    await w
      .findAll("nav button")
      .find((b) => b.text() === "2")!
      .trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(url, {
      params: { page: 2, per_page: 20, sucursal_id: "s1" },
    });
  });

  it("con fechas vuelve a la primera página y se puede quitar el filtro", async () => {
    const w = mount(VentasMostrador, { global: { plugins: [i18n] } });
    await flushPromises();

    await w.get('[data-prueba="ventas-desde"]').setValue("2026-09-01");
    await w.get('[data-prueba="ventas-hasta"]').setValue("2026-09-30");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(url, {
      params: {
        page: 1,
        per_page: 20,
        desde: "2026-09-01",
        hasta: "2026-09-30",
      },
    });

    api.get.mockResolvedValue({
      data: {
        data: [],
        meta: { total: 0, page: 1, per_page: 20, ultima_pagina: 1 },
      },
    });
    await w.get('[data-prueba="ventas-quitar-filtro"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(url, {
      params: { page: 1, per_page: 20 },
    });
    expect(w.text()).toContain("Aún no hay ventas.");
  });

  it("en la ficha de una persona: solo las suyas, sin decir a quién", async () => {
    const w = mount(VentasMostrador, {
      props: { personaId: "p1", porPagina: 5 },
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(url, {
      params: { page: 1, per_page: 5, persona_id: "p1" },
    });
    expect(w.find('[data-prueba="venta-comprador"]').exists()).toBe(false);
    expect(w.find('[data-prueba="corregir-venta"]').exists()).toBe(false);
    expect(w.text()).toContain("1 × Café americano");
  });
});
