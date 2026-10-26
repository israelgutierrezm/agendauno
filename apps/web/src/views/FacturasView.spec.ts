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
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
// La dirección: la venta que se factura llega como `?orden=` o `?venta=`.
const ruta = vi.hoisted(() => ({ query: {} as Record<string, string> }));
const reemplazar = vi.hoisted(() => vi.fn());
vi.mock("vue-router", async () => {
  const { reactive } = await import("vue");
  const r = reactive(ruta);
  return {
    useRoute: () => r,
    useRouter: () => ({
      replace: (destino: { query: Record<string, string> }) => {
        reemplazar(destino);
        r.query = destino.query;
      },
    }),
    RouterLink: { template: "<a><slot /></a>" },
  };
});
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
  ruta.query = {};
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
    // El precio va antes de IVA: se ve el total que se timbra.
    expect(w.get('[data-prueba="totales-factura"]').text()).toBe(
      "Subtotal $100.00 · IVA 16 % $16.00 · Total $116.00",
    );
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

describe("facturar una venta (ADR 0179)", () => {
  const borrador = {
    etiqueta: "Pack 8 clases",
    receptor: {
      nombre: "Público en general",
      email: null,
      rfc: "XAXX010101000",
      codigo_postal: "06700",
      uso_cfdi: "S01",
    },
    conceptos: [
      {
        descripcion: "Pack 8 clases",
        cantidad: 1,
        precio_unitario_minor: 77500,
        clave_prod_serv: "86121600",
        clave_unidad: "E48",
      },
    ],
    forma_pago: "01",
    total_minor: 89900,
    moneda: "MXN",
  };

  it("el borrador llena el formulario y, al timbrar, la factura queda ligada a la venta", async () => {
    vi.mocked(confirmar).mockResolvedValue(true);
    api.post.mockResolvedValue({ data: { data: factura("3", "timbrada") } });
    ruta.query = { orden: "ORD1" };
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.endsWith("/facturas/borrador")
          ? { data: borrador }
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

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/facturas/borrador", {
      params: { orden: "ORD1" },
    });
    expect(w.get('[data-prueba="venta-ligada"]').text()).toContain(
      "Pack 8 clases",
    );
    expect((w.get("#fa-rfc").element as HTMLInputElement).value).toBe(
      "XAXX010101000",
    );
    expect((w.get("#fa-precio-0").element as HTMLInputElement).value).toBe(
      "775.00",
    );
    // Un anticipo no factura la venta.
    expect(w.find('[data-prueba="es-anticipo"]').exists()).toBe(false);

    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/facturas",
      expect.objectContaining({
        orden_id: "ORD1",
        uso_cfdi: "S01",
        forma_pago: "01",
        items: [expect.objectContaining({ precio_unitario_minor: 77500 })],
      }),
    );
    // Ya facturada, la venta sale de la dirección y el formulario queda vacío.
    expect(reemplazar).toHaveBeenLastCalledWith({ query: {} });
    expect(w.find('[data-prueba="venta-ligada"]').exists()).toBe(false);
    expect((w.get("#fa-rfc").element as HTMLInputElement).value).toBe("");
    w.unmount();
  });

  it("una venta ya facturada no se liga: lo dice el API", async () => {
    ruta.query = { venta: "VTA1" };
    api.get.mockImplementation((url: string) =>
      url.endsWith("/facturas/borrador")
        ? Promise.reject(new Error("422"))
        : Promise.resolve({
            data: {
              data: [],
              meta: { total: 0, page: 1, per_page: 20, ultima_pagina: 1 },
            },
          }),
    );
    const w = mount(FacturasView, {
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/facturas/borrador", {
      params: { venta: "VTA1" },
    });
    expect(w.get('[data-prueba="error-emision"]').text()).toBe("Error");
    expect(w.find('[data-prueba="venta-ligada"]').exists()).toBe(false);
    w.unmount();
  });
});

describe("cancelar, sustituir y notas de crédito (ADR 0180)", () => {
  const listaCon = (filas: Record<string, unknown>[]) =>
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.endsWith("/facturas")
          ? {
              data: filas,
              meta: {
                total: filas.length,
                page: 1,
                per_page: 20,
                ultima_pagina: 1,
              },
            }
          : { data: {} },
      }),
    );

  it("cada factura se cancela o se acredita y dice cómo va su cancelación", async () => {
    listaCon([
      {
        ...factura("1", "timbrada"),
        cancelable: true,
        acreditable_minor: 11600,
      },
      {
        ...factura("2", "timbrada"),
        cancelable: false,
        acreditable_minor: 0,
        cancelacion: { estado: "pendiente", motivo: "02", detalle: null },
      },
      {
        ...factura("3", "timbrada"),
        tipo: "nota_de_credito",
        relacionada: { id: "1", uuid: "uuid-1abcdef", relacion: "01" },
        cancelable: true,
        acreditable_minor: 0,
      },
    ]);
    api.post.mockResolvedValue({ data: { data: { estado: "timbrada" } } });
    const w = mount(FacturasView, {
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    await flushPromises();

    expect(w.findAll('[data-prueba="cancelar-factura"]')).toHaveLength(2);
    expect(w.findAll('[data-prueba="nota-credito"]')).toHaveLength(1);
    expect(w.get('[data-prueba="cancelacion-en-espera"]').text()).toBe(
      "Cancelación en espera del cliente",
    );
    expect(w.get('[data-prueba="factura-relacionada"]').text()).toBe(
      "Nota de crédito de UUID-1AB",
    );

    // Cancelar: se elige el motivo y, si espera al cliente, se dice.
    await w.findAll('[data-prueba="cancelar-factura"]')[0]!.trigger("click");
    await w.get('[data-prueba="motivo-03"]').setValue(true);
    await w.get("#form-cancelar-factura").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/facturas/1/cancelar",
      { motivo: "03" },
    );
    expect(w.get('[data-prueba="aviso-facturas"]').text()).toContain(
      "queda en espera",
    );
    w.unmount();
  });

  it("la sustituta trae lo timbrado y manda la factura que sustituye", async () => {
    vi.mocked(confirmar).mockResolvedValue(true);
    ruta.query = { sustituye: "F1" };
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.endsWith("/facturas/borrador")
          ? {
              data: {
                etiqueta: "UUID-F1",
                receptor: {
                  nombre: "Ana López",
                  email: null,
                  rfc: "LOAA800101AB1",
                  codigo_postal: "06700",
                  uso_cfdi: "S01",
                  regimen_fiscal: "605",
                },
                conceptos: [
                  {
                    descripcion: "Pack 8 clases",
                    cantidad: 1,
                    precio_unitario_minor: 77500,
                    clave_prod_serv: "86121600",
                    clave_unidad: "E48",
                  },
                ],
                forma_pago: "01",
                total_minor: 89900,
                moneda: "MXN",
                anticipo: false,
              },
            }
          : {
              data: [],
              meta: { total: 0, page: 1, per_page: 20, ultima_pagina: 1 },
            },
      }),
    );
    api.post.mockResolvedValue({
      data: {
        data: factura("2", "timbrada"),
        meta: { aviso_cancelacion: "FacturAPI no respondió." },
      },
    });
    const w = mount(FacturasView, {
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/facturas/borrador", {
      params: { sustituye: "F1" },
    });
    expect(w.get('[data-prueba="venta-ligada"]').text()).toContain(
      "Sustituyes la factura UUID-F1",
    );
    await w.get("#fa-rfc").setValue("LOAA800101AB2");
    await w.get("form").trigger("submit");
    await flushPromises();
    const enviado = api.post.mock.calls[0]![1] as Record<string, unknown>;
    expect(enviado).toMatchObject({
      sustituye: "F1",
      receptor: expect.objectContaining({
        rfc: "LOAA800101AB2",
        regimen_fiscal: "605",
      }),
    });
    expect(enviado).not.toHaveProperty("orden_id");
    // La nueva se timbró pero la anterior no se canceló: se dice.
    expect(w.get('[data-prueba="aviso-facturas"]').text()).toContain(
      "FacturAPI no respondió.",
    );
    w.unmount();
  });
});
