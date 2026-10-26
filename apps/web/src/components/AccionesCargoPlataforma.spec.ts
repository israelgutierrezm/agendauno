import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { plataformaAdmin } from "@/i18n/locales/gestion.es-MX";
import AccionesCargoPlataforma from "./AccionesCargoPlataforma.vue";

/*
| La renta a mano en la ficha del superadmin (ADR 0214): un cargo pendiente registra su
| pago con el método; uno pagado con la factura en error se reintenta; una timbrada se
| cancela con un motivo del SAT.
*/

const mocks = vi.hoisted(() => ({
  post: vi.fn(),
  get: vi.fn(),
  exito: vi.fn(),
  error: vi.fn(),
}));
vi.mock("@/lib/clientePlataforma", () => ({
  crearClientePlataforma: () => ({ post: mocks.post, get: mocks.get }),
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: mocks.exito, error: mocks.error }),
}));
vi.mock("@/lib/api", () => ({ mensajeDeError: (e: Error) => e.message }));

function montar(cargo: Record<string, unknown>) {
  return mount(AccionesCargoPlataforma, {
    props: {
      apiUrl: "http://api",
      token: "tk",
      cargo: {
        id: "C1",
        moneda: "MXN",
        monto_minor: 149900,
        ...cargo,
      } as never,
    },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: {
            es: { plataformaAdmin, comun: { cancelar: "Cancelar" } },
          },
        }),
      ],
    },
  });
}

describe("acciones de un cargo de la renta", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.post.mockResolvedValue({ data: { data: {} } });
  });

  it("registra el pago de un cargo pendiente con su método y referencia", async () => {
    const w = montar({ estado: "pendiente" });
    await w.get('[data-prueba="abrir-pago-manual"]').trigger("click");
    await w.get("select").setValue("efectivo");
    await w.get('input:not([type="date"])').setValue("Caja 3");
    await w.get('[data-prueba="pago-manual"]').trigger("submit");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "http://api/api/v1/plataforma/cargos/C1/pago",
      expect.objectContaining({ metodo: "efectivo", referencia: "Caja 3" }),
      expect.anything(),
    );
    expect(w.emitted("cambiado")).toHaveLength(1);
  });

  it("con la factura en error dice por qué y la reintenta", async () => {
    const w = montar({
      estado: "pagado",
      factura_detalle: {
        id: "F1",
        estado: "error",
        uuid: null,
        motivo_error: "RFC inválido",
        forma_pago: null,
        cancelacion_estado: null,
        cancelada_en: null,
      },
    });
    expect(w.text()).toContain("RFC inválido");
    await w.get('[data-prueba="facturar-cargo"]').trigger("click");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "http://api/api/v1/plataforma/cargos/C1/factura",
      {},
      expect.anything(),
    );
  });

  it("cancela una factura timbrada con el motivo del SAT", async () => {
    const w = montar({
      estado: "pagado",
      factura_detalle: {
        id: "F1",
        estado: "timbrada",
        uuid: "UUID-1",
        motivo_error: null,
        forma_pago: "03",
        cancelacion_estado: null,
        cancelada_en: null,
      },
    });
    expect(w.find('[data-prueba="facturar-cargo"]').exists()).toBe(false);
    await w.get('[data-prueba="abrir-cancelar-factura"]').trigger("click");
    await w.get('[data-prueba="cancelar-factura"] select').setValue("03");
    await w.get('[data-prueba="cancelar-factura"]').trigger("submit");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "http://api/api/v1/plataforma/facturas/F1/cancelar",
      { motivo: "03" },
      expect.anything(),
    );
  });
});
