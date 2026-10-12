import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ComprasMostrador from "./ComprasMostrador.vue";

/*
| Lo que cada quien compró en el mostrador (ADR 0145): sus tickets con lo que llevó,
| cuándo, dónde y cuánto pagó. Si nunca compró a su nombre, la sección no aparece.
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    pais: "MX",
    zonaHoraria: "America/Mexico_City",
  }),
}));

beforeEach(() => vi.clearAllMocks());

describe("compras en mostrador", () => {
  it("muestra sus tickets con lo que llevó y lo que pagó", async () => {
    api.get.mockResolvedValue({
      data: {
        data: [
          {
            id: "v1",
            folio: "7Q2KX9PA",
            creado_en: "2026-10-01T18:00:00Z",
            sucursal: "Roma Norte",
            lineas: [
              { articulo: "Proteína", cantidad: 1, extras: ["Fresas"] },
              { articulo: "Agua", cantidad: 2, extras: [] },
            ],
            propina_minor: 1000,
            cobrado_minor: 11000,
            moneda: "MXN",
            anulada: false,
          },
          {
            id: "v2",
            folio: "3B8MZ1RT",
            creado_en: "2026-09-28T18:00:00Z",
            sucursal: "Roma Norte",
            lineas: [{ articulo: "Agua", cantidad: 1, extras: [] }],
            propina_minor: 0,
            cobrado_minor: 2000,
            moneda: "MXN",
            anulada: true,
          },
        ],
        meta: { total: 2, page: 1, per_page: 5, ultima_pagina: 1 },
      },
    });
    const w = mount(ComprasMostrador, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/compras-mostrador",
      { params: { page: 1, per_page: 5 } },
    );
    const filas = w.findAll('[data-prueba="compra-mostrador"]');
    expect(filas[0]!.text()).toContain("1 × Proteína (Fresas), 2 × Agua");
    expect(filas[0]!.text()).toContain("Ticket 7Q2KX9PA");
    expect(filas[0]!.text()).toContain("$110.00");
    expect(filas[0]!.text()).toContain("Incluye $10.00 de propina");
    expect(filas[1]!.text()).toContain("Anulada");
  });

  it("si nunca compró a su nombre, no aparece", async () => {
    api.get.mockResolvedValue({
      data: {
        data: [],
        meta: { total: 0, page: 1, per_page: 5, ultima_pagina: 1 },
      },
    });
    const w = mount(ComprasMostrador, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(w.find('[data-prueba="compras-mostrador"]').exists()).toBe(false);
  });
});
