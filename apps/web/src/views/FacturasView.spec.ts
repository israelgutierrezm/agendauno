import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import FacturasView from "./FacturasView.vue";

/*
| Facturas: por páginas, con búsqueda, estado (con sus cuentas) y fechas del negocio.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn() }));
vi.mock("vue-router", () => ({
  useRoute: () => undefined,
  useRouter: () => undefined,
  RouterLink: { template: "<a><slot /></a>" },
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: () => true,
    zonaHoraria: "America/Mexico_City",
    moneda: "MXN",
    pais: "MX",
    estudio: {},
  }),
}));

const factura = (id: string, estado: string) => ({
  id,
  estado,
  receptor: { nombre: `Cliente ${id}`, rfc: "XAXX010101000" },
  moneda: "MXN",
  total_minor: 11600,
  uuid: estado === "timbrada" ? `UUID-${id}` : null,
  pdf_url: null,
  xml_url: null,
  motivo_error: estado === "error" ? "RFC inválido" : null,
});

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/facturas")
        ? {
            data: [factura("1", "timbrada"), factura("2", "error")],
            meta: {
              total: 45,
              page: 1,
              per_page: 20,
              ultima_pagina: 3,
              conteos: { timbrada: 40, error: 5 },
            },
          }
        : { data: {} },
    }),
  );
});

describe("facturas: búsqueda, filtros y páginas", () => {
  it("filtra por estado (con sus cuentas) y pagina en el API", async () => {
    const w = mount(FacturasView, {
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/facturas", {
      params: { page: 1 },
    });
    expect(w.get('[data-prueba="facturas-error"]').text()).toContain("5");
    expect(w.get('[data-prueba="facturas-timbrada"]').text()).toContain("40");

    await w.get('[data-prueba="facturas-error"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/facturas", {
      params: { page: 1, estado: "error" },
    });

    // Con filtros, se pueden limpiar de un golpe.
    await w.get('[data-prueba="limpiar-facturas"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/facturas", {
      params: { page: 1 },
    });
    w.unmount();
  });
});
