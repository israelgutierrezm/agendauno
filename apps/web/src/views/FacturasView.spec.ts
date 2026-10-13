import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import { confirmar } from "@/lib/confirmar";
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

  it("el total del encabezado es el de todas las páginas", async () => {
    const w = mount(FacturasView, {
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    await flushPromises();
    expect(w.get(".es-total").text()).toBe("45");
    w.unmount();
  });

  it("el rechazo del proveedor se queda en el formulario aunque la lista se recargue", async () => {
    vi.mocked(confirmar).mockResolvedValue(true);
    api.post.mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          data: {
            estado: "error",
            motivo_error: "RFC no registrado en el SAT",
          },
        },
      },
    });
    const w = mount(FacturasView, {
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    await flushPromises();

    // Cada campo tiene su etiqueta visible.
    expect(w.get('label[for="fa-rfc"]').text()).toContain("RFC");
    expect(w.get("#fa-precio-0").attributes("min")).toBe("0.01");
    await w.get("#fa-nombre").setValue("Ana López");
    await w.get("#fa-rfc").setValue("LOAA800101AB1");
    await w.get("#fa-cp").setValue("06700");
    await w.get("#fa-desc-0").setValue("Clase de yoga");
    await w.get("#fa-precio-0").setValue("100");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledTimes(1);
    // Se recargó la lista y el error sigue a la vista, junto al formulario.
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(w.get('[data-prueba="error-emision"]').text()).toBe(
      "RFC no registrado en el SAT",
    );
    expect((w.get("#fa-nombre").element as HTMLInputElement).value).toBe(
      "Ana López",
    );
    w.unmount();
  });
});
