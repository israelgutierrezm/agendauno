import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import RentaView from "./RentaView.vue";

/*
| Renta: «Facturar» solo cuando el negocio puede recibir la factura. Con otra
| moneda u otro país (o si la plataforma aún no factura), de cada cargo pagado se
| descarga un recibo sin valor fiscal.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
const sesion = vi.hoisted(() => ({
  slug: "demo",
  suspendido: false,
  estudio: { facturacion_disponible: true } as {
    facturacion_disponible?: boolean;
  },
}));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ replace: vi.fn() }),
}));

const desglose = {
  lineas: [],
  subtotal_minor: 0,
  iva_porcentaje: 16,
  iva_minor: 0,
  total_minor: 0,
};

function renta(facturaRentaPosible: boolean) {
  return {
    modalidad: "clases",
    modo_cobro: "fijo",
    moneda: "MXN",
    cuota_fija_minor: 149900,
    trial_termina_en: null,
    factura_renta_posible: facturaRentaPosible,
    actual: {
      periodo: "2026-10",
      metrica: "alumnos_activos",
      cantidad: 0,
      detalle: {},
      desglose,
      cargo_estimado_minor: 0,
    },
    cargos: [
      {
        id: "c1",
        periodo: "2026-09",
        modo_cobro: "fijo",
        metrica: "alumnos_activos",
        cantidad: 0,
        desglose: null,
        monto_minor: 149900,
        moneda: "MXN",
        estado: "pagado",
        vence_en: "2026-10-10",
        pagado_en: "2026-10-03T17:20:00Z",
        factura: null,
      },
    ],
  };
}

async function montar(facturaRentaPosible: boolean) {
  api.get.mockResolvedValueOnce({
    data: { data: renta(facturaRentaPosible) },
  });
  const w = mount(RentaView, {
    global: { plugins: [i18n], stubs: { AvisosAgendaUno: true } },
  });
  await flushPromises();
  return w;
}

beforeEach(() => {
  api.get.mockReset();
  sesion.estudio = { facturacion_disponible: true };
});

describe("factura o recibo de la renta", () => {
  it("si puede recibir la factura, ofrece «Facturar» y no el recibo", async () => {
    const w = await montar(true);

    expect(w.find('[data-prueba="facturar"]').text()).toBe("Facturar");
    expect(w.find('[data-prueba="recibo"]').exists()).toBe(false);
    expect(w.text()).toContain("Tu factura (CFDI)");
  });

  it("sin factura posible, no ofrece «Facturar» y descarga el recibo", async () => {
    const w = await montar(false);

    expect(w.find('[data-prueba="facturar"]').exists()).toBe(false);
    expect(w.text()).not.toContain("CFDI");
    expect(w.text()).toContain("recibo sin valor fiscal");

    const crear = vi.fn(() => "blob:recibo");
    Object.defineProperty(URL, "createObjectURL", {
      configurable: true,
      value: crear,
    });
    Object.defineProperty(URL, "revokeObjectURL", {
      configurable: true,
      value: vi.fn(),
    });
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, "click")
      .mockImplementation(() => {});
    api.get.mockResolvedValueOnce({ data: new Blob(["%PDF-1.4"]) });

    const boton = w.get('[data-prueba="recibo"]');
    expect(boton.text()).toBe("Descargar recibo");
    await boton.trigger("click");
    await flushPromises();

    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/renta/cargos/c1/recibo",
      { responseType: "blob" },
    );
    expect(crear).toHaveBeenCalled();
    click.mockRestore();
  });

  it("si la plataforma aún no factura, también da el recibo", async () => {
    sesion.estudio = { facturacion_disponible: false };
    const w = await montar(true);

    expect(w.find('[data-prueba="facturar"]').exists()).toBe(false);
    expect(w.find('[data-prueba="recibo"]').exists()).toBe(true);
  });
});
