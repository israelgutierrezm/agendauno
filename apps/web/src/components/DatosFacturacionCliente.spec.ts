import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import DatosFacturacionCliente from "./DatosFacturacionCliente.vue";

/*
| Los datos para facturar del cliente: se guardan con su error junto a cada campo y el
| interruptor de la factura automática solo aparece si el negocio la ofrece.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), delete: vi.fn() }));
const errores = vi.hoisted(() => ({ valor: {} as Record<string, string> }));
vi.mock("@/lib/api", () => ({
  api,
  erroresPorCampo: () => errores.valor,
  mensajeDeError: () => "Error",
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));

const URL = "/api/v1/app/barberia/mi/datos-fiscales";

async function montar(disponible: boolean) {
  api.get.mockResolvedValue({
    data: { data: null, meta: { automatica_disponible: disponible } },
  });
  const w = mount(DatosFacturacionCliente, {
    props: { url: URL, propios: true },
    global: { plugins: [i18n] },
  });
  await flushPromises();
  return w;
}

beforeEach(() => {
  vi.clearAllMocks();
  errores.valor = {};
});

describe("datos para facturar", () => {
  it("guarda los datos y pide la factura automática si el negocio la ofrece", async () => {
    const w = await montar(true);
    await w.get("#df-rfc").setValue("GODE561231GR8");
    await w.get("#df-cp").setValue("06700");
    await w.get("#df-nombre").setValue("Alberto Gómez");
    await w.get("#df-regimen").setValue("612");
    await w.get('[data-prueba="factura-automatica"]').trigger("click");
    api.put.mockResolvedValue({
      data: {
        data: {
          rfc: "GODE561231GR8",
          nombre: "ALBERTO GÓMEZ",
          codigo_postal: "06700",
          regimen_fiscal: "612",
          uso_cfdi: "G03",
          email: null,
          factura_automatica: true,
        },
      },
    });
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      URL,
      expect.objectContaining({
        rfc: "GODE561231GR8",
        regimen_fiscal: "612",
        uso_cfdi: "G03",
        factura_automatica: true,
        email: null,
      }),
    );
    expect(toast.exito).toHaveBeenCalled();
  });

  it("sin factura automática en el negocio no hay interruptor; los errores van junto a su campo", async () => {
    const w = await montar(false);
    expect(w.find('[data-prueba="factura-automatica"]').exists()).toBe(false);

    errores.valor = { codigo_postal: "El código postal debe tener 5 dígitos." };
    api.put.mockRejectedValue(new Error("422"));
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(w.text()).toContain("El código postal debe tener 5 dígitos.");
    expect(w.get("#df-cp").attributes("aria-invalid")).toBe("true");
    expect(toast.error).not.toHaveBeenCalled();
  });
});
