import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import MisFacturas from "./MisFacturas.vue";

/*
| Mis facturas (ADR 0212 y 0228): lo que el negocio le timbró, con su PDF y su XML, por
| páginas y por periodo. Mientras carga lo dice, si falla deja reintentar y sin facturas
| lo dice claro; si el negocio no factura y no tiene ninguna, no aparece.
*/

const mocks = vi.hoisted(() => ({ get: vi.fn(), descargar: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get },
  mensajeDeError: () => "Error",
}));
vi.mock("@/lib/descargas", () => ({ descargarDeApi: mocks.descargar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    pais: "MX",
    zonaHoraria: "America/Mexico_City",
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const URL = "/api/v1/app/demo/mi/facturas";

function factura(id: string) {
  return {
    id,
    tipo: "I",
    uuid: `UUID-${id}`,
    total_minor: 116000,
    moneda: "MXN",
    receptor_rfc: "XAXX010101000",
    timbrada_en: "2026-10-20T10:00:00-06:00",
    cancelada: false,
  };
}

function respuesta(
  datos: ReturnType<typeof factura>[],
  meta: Partial<{
    page: number;
    ultima_pagina: number;
    total: number;
    per_page: number;
    disponible: boolean;
  }> = {},
) {
  return {
    data: {
      data: datos,
      meta: {
        page: 1,
        ultima_pagina: 1,
        total: datos.length,
        per_page: 10,
        disponible: true,
        ...meta,
      },
    },
  };
}

function montar() {
  return mount(MisFacturas, { global: { plugins: [i18n] } });
}

describe("mis facturas", () => {
  beforeEach(() => vi.clearAllMocks());

  it("mientras carga lo dice", async () => {
    mocks.get.mockReturnValueOnce(new Promise(() => {}));
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="mis-facturas-cargando"]').text()).toBe(
      "Cargando…",
    );
  });

  it("si no pudo cargarlas lo dice y deja reintentar", async () => {
    mocks.get
      .mockRejectedValueOnce(new Error("sin red"))
      .mockResolvedValueOnce(respuesta([factura("01F")]));
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="mis-facturas-error"]').text()).toContain(
      "No pudimos cargar tus facturas.",
    );

    await w.find('[data-prueba="mis-facturas-reintentar"]').trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="mis-facturas-error"]').exists()).toBe(false);
    expect(w.findAll('[data-prueba="mi-factura"]')).toHaveLength(1);
  });

  it("si falla otra página, reintentar vuelve a pedir esa", async () => {
    mocks.get
      .mockResolvedValueOnce(
        respuesta([factura("01A")], {
          ultima_pagina: 2,
          total: 2,
          per_page: 1,
        }),
      )
      .mockRejectedValueOnce(new Error("sin red"))
      .mockResolvedValueOnce(
        respuesta([factura("01B")], {
          page: 2,
          ultima_pagina: 2,
          total: 2,
          per_page: 1,
        }),
      );
    const w = montar();
    await flushPromises();

    await w.find('button[aria-label="Página 2"]').trigger("click");
    await flushPromises();
    await w.find('[data-prueba="mis-facturas-reintentar"]').trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith(URL, {
      params: { page: 2, per_page: 10 },
    });
    expect(w.findAll('[data-prueba="mi-factura"]')).toHaveLength(1);
  });

  it("sin facturas en un negocio que factura, lo dice claro", async () => {
    mocks.get.mockResolvedValueOnce(respuesta([]));
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="mis-facturas-vacio"]').text()).toContain(
      "Aún no tienes facturas.",
    );
  });

  it("sin facturas en un negocio que no factura, no aparece", async () => {
    mocks.get.mockResolvedValueOnce(respuesta([], { disponible: false }));
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="mis-facturas"]').exists()).toBe(false);
  });

  it("lista sus facturas y descarga el PDF y el XML", async () => {
    mocks.get.mockResolvedValueOnce(respuesta([factura("01F")]));
    const w = montar();
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith(URL, {
      params: { page: 1, per_page: 10 },
    });
    const botones = w.findAll('[data-prueba="mi-factura"] button');
    await botones[0]!.trigger("click");
    await botones[1]!.trigger("click");
    expect(mocks.descargar).toHaveBeenCalledWith(
      `${URL}/01F/pdf`,
      "factura-UUID-01F.pdf",
    );
    expect(mocks.descargar).toHaveBeenCalledWith(
      `${URL}/01F/xml`,
      "factura-UUID-01F.xml",
    );
  });

  it("pasa de página", async () => {
    mocks.get
      .mockResolvedValueOnce(
        respuesta([factura("01A"), factura("01B")], {
          ultima_pagina: 2,
          total: 3,
          per_page: 2,
        }),
      )
      .mockResolvedValueOnce(
        respuesta([factura("01C")], {
          page: 2,
          ultima_pagina: 2,
          total: 3,
          per_page: 2,
        }),
      );
    const w = montar();
    await flushPromises();

    await w.find('button[aria-label="Página 2"]').trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith(URL, {
      params: { page: 2, per_page: 10 },
    });
    const filas = w.findAll('[data-prueba="mi-factura"]');
    expect(filas).toHaveLength(1);
    expect(filas[0]!.text()).toContain("XAXX010101000");
  });

  it("filtra por periodo desde la primera página, y sin resultados deja quitar el filtro", async () => {
    mocks.get
      .mockResolvedValueOnce(respuesta([factura("01A")], { total: 1 }))
      .mockResolvedValue(respuesta([], { total: 0 }));
    const w = montar();
    await flushPromises();

    await w.find('[data-prueba="mis-facturas-desde"]').setValue("2026-09-01");
    await w.find('[data-prueba="mis-facturas-hasta"]').setValue("2026-09-30");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith(URL, {
      params: {
        page: 1,
        per_page: 10,
        desde: "2026-09-01",
        hasta: "2026-09-30",
      },
    });
    const vacio = w.find('[data-prueba="mis-facturas-vacio"]');
    expect(vacio.exists()).toBe(true);

    await vacio.find("button").trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith(URL, {
      params: { page: 1, per_page: 10 },
    });
  });
});
