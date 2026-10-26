import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import MisFacturas from "./MisFacturas.vue";

/*
| Mis facturas (ADR 0212): lo que el negocio le timbró, con su PDF y su XML; sin
| facturas no aparece.
*/

const mocks = vi.hoisted(() => ({ get: vi.fn(), descargar: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get },
  mensajeDeError: () => "Error",
}));
vi.mock("@/lib/descargas", () => ({ descargarDeApi: mocks.descargar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", pais: "MX" }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

function montar() {
  return mount(MisFacturas, { global: { plugins: [i18n] } });
}

describe("mis facturas", () => {
  beforeEach(() => vi.clearAllMocks());

  it("sin facturas no aparece", async () => {
    mocks.get.mockResolvedValueOnce({ data: { data: [] } });
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="mis-facturas"]').exists()).toBe(false);
  });

  it("lista sus facturas y descarga el PDF y el XML", async () => {
    mocks.get.mockResolvedValueOnce({
      data: {
        data: [
          {
            id: "01F",
            tipo: "ingreso",
            uuid: "UUID-1",
            total_minor: 116000,
            moneda: "MXN",
            receptor_rfc: "XAXX010101000",
            timbrada_en: "2026-10-20T10:00:00-06:00",
            cancelada: false,
          },
        ],
      },
    });
    const w = montar();
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith("/api/v1/app/demo/mi/facturas");
    const botones = w.findAll('[data-prueba="mis-facturas"] button');
    await botones[0]!.trigger("click");
    await botones[1]!.trigger("click");
    expect(mocks.descargar).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/facturas/01F/pdf",
      "factura-UUID-1.pdf",
    );
    expect(mocks.descargar).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/facturas/01F/xml",
      "factura-UUID-1.xml",
    );
  });
});
