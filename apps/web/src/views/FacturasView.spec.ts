import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import { confirmar } from "@/lib/confirmar";
import FacturasView from "./FacturasView.vue";
import SelectorBuscable from "@/components/SelectorBuscable.vue";

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
    // Se recargó la lista (sin contar la búsqueda de anticipos del RFC) y el error
    // sigue a la vista, junto al formulario.
    const recargas = api.get.mock.calls.filter(
      ([, opciones]) =>
        (opciones as { params?: Record<string, unknown> } | undefined)?.params
          ?.anticipos_de === undefined,
    );
    expect(recargas).toHaveLength(2);
    expect(w.get('[data-prueba="error-emision"]').text()).toBe(
      "RFC no registrado en el SAT",
    );
    expect((w.get("#fa-nombre").element as HTMLInputElement).value).toBe(
      "Ana López",
    );
    w.unmount();
  });

  it("el receptor, el uso y la forma de pago se eligen del catálogo del SAT", async () => {
    vi.mocked(confirmar).mockResolvedValue(true);
    api.post.mockResolvedValue({ data: { data: factura("3", "timbrada") } });
    const w = mount(FacturasView, {
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    await flushPromises();
    const selectores = w.findAllComponents(SelectorBuscable);
    expect(selectores).toHaveLength(3);

    // RFC de persona moral (12 caracteres): un uso solo de personas físicas avisa.
    await w.get("#fa-nombre").setValue("Estudio Sol SA");
    await w.get("#fa-rfc").setValue("ESO800101AB1");
    await w.get("#fa-cp").setValue("06700");
    const [regimen, uso, forma] = selectores;
    await uso!.setValue("D10");
    expect(w.get('[data-prueba="aviso-uso-cfdi"]').text()).toContain(
      "solo para personas físicas",
    );
    await uso!.setValue("G03");
    expect(w.find('[data-prueba="aviso-uso-cfdi"]').exists()).toBe(false);
    await regimen!.setValue("601");
    await forma!.setValue("03");
    await w.get("#fa-desc-0").setValue("Clase de yoga");
    await w.get("#fa-precio-0").setValue("100");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/facturas",
      expect.objectContaining({
        receptor: expect.objectContaining({ regimen_fiscal: "601" }),
        uso_cfdi: "G03",
        forma_pago: "03",
      }),
    );
    w.unmount();
  });

  it("factura un anticipo con el concepto del SAT y al facturar el resto lo aplica (ADR 0163)", async () => {
    vi.mocked(confirmar).mockResolvedValue(true);
    api.post.mockResolvedValue({ data: { data: factura("3", "timbrada") } });
    // Un anticipo timbrado y sin aplicar de ese RFC.
    api.get.mockImplementation(
      (url: string, opciones?: { params?: Record<string, unknown> }) =>
        Promise.resolve({
          data:
            opciones?.params?.anticipos_de !== undefined
              ? {
                  data: [
                    {
                      ...factura("9", "timbrada"),
                      anticipo: true,
                      anticipo_aplicado: false,
                      timbrada_en: "2026-10-01T12:00:00-06:00",
                    },
                  ],
                }
              : {
                  data: [],
                  meta: { total: 0, page: 1, per_page: 20, ultima_pagina: 1 },
                },
        }),
    );
    const w = mount(FacturasView, {
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    await flushPromises();
    await w.get("#fa-nombre").setValue("Cliente Final");
    await w.get("#fa-cp").setValue("06700");
    const uso = w.findAllComponents(SelectorBuscable)[1]!;
    await uso.setValue("G03");

    // El anticipo: solo su importe; sin conceptos libres ni anticipos por aplicar.
    await w.get('[data-prueba="es-anticipo"]').setValue(true);
    await w.get("#fa-rfc").setValue("XAXX010101000");
    await flushPromises();
    expect(w.find('[data-prueba="concepto"]').exists()).toBe(false);
    expect(w.find('[data-prueba="anticipos-por-aplicar"]').exists()).toBe(
      false,
    );
    await w.get('[data-prueba="importe-anticipo"]').setValue("200");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/facturas",
      expect.objectContaining({
        anticipo: true,
        items: [
          expect.objectContaining({
            cantidad: 1,
            precio_unitario_minor: 20000,
            clave_prod_serv: "84111506",
            clave_unidad: "ACT",
          }),
        ],
      }),
    );

    // El resto: ofrece el anticipo de ese RFC y lo manda para relacionarlo.
    await w.get("#fa-nombre").setValue("Cliente Final");
    await w.get("#fa-cp").setValue("06700");
    await w.get("#fa-rfc").setValue("XAXX010101000");
    await flushPromises();
    expect(w.get('[data-prueba="anticipos-por-aplicar"]').text()).toContain(
      "UUID-9",
    );
    await w.get('[data-prueba="aplicar-anticipo"]').setValue(true);
    await w.get("#fa-desc-0").setValue("Corte y color");
    await w.get("#fa-precio-0").setValue("300");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/facturas",
      expect.objectContaining({ anticipos: ["9"] }),
    );
    w.unmount();
  });
});
