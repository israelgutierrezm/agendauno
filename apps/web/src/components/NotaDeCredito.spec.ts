import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import { confirmar } from "@/lib/confirmar";
import NotaDeCredito from "./NotaDeCredito.vue";
import SelectorBuscable from "./SelectorBuscable.vue";

/*
| La nota de crédito de una factura (ADR 0180): por lo que queda por acreditar, con la
| forma en que se devolvió y, si la factura no lo guardó, el régimen del cliente.
*/

const api = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Error",
  erroresPorCampo: (e: unknown) =>
    (e as { campos?: Record<string, string> })?.campos ?? {},
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn() }));

const dinero = (m: number) => `$${(m / 100).toFixed(2)}`;
const factura = {
  id: "F1",
  receptor: { nombre: "Cliente Final", rfc: "XAXX010101000" },
  acreditable_minor: 116000,
  forma_pago: "03",
  regimen_receptor: null,
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(confirmar).mockResolvedValue(true);
  api.post.mockResolvedValue({ data: { data: { estado: "timbrada" } } });
});

describe("nota de crédito", () => {
  it("parte de lo que queda, pide el régimen que falta y manda la nota", async () => {
    const w = mount(NotaDeCredito, {
      props: { base: "/api/v1/app/demo", factura: null, dinero },
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    await w.setProps({ factura });

    const monto = w.get('[data-prueba="monto-nota"]')
      .element as HTMLInputElement;
    expect(monto.value).toBe("1160.00");
    expect(monto.max).toBe("1160");
    // Sin régimen no se timbra.
    expect(
      w.get('[data-prueba="timbrar-nota"]').attributes("disabled"),
    ).toBeDefined();

    await w.get('[data-prueba="monto-nota"]').setValue("580");
    const [forma, regimen] = w.findAllComponents(SelectorBuscable);
    expect(forma!.props("modelValue")).toBe("03");
    await regimen!.setValue("616");
    await w.get("#form-nota-credito").trigger("submit");
    await flushPromises();

    expect(confirmar).toHaveBeenCalledWith(
      "¿Timbrar una nota de crédito por $580.00 para XAXX010101000?",
      { aceptar: "Timbrar la nota" },
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/facturas/F1/notas-credito",
      {
        monto_minor: 58000,
        descripcion: null,
        forma_pago: "03",
        regimen_fiscal: "616",
      },
    );
    expect(w.emitted("hecha")).toHaveLength(1);
  });

  it("con el régimen guardado no lo pide, y el error de un campo va junto a él", async () => {
    api.post.mockRejectedValue({
      campos: { monto_minor: "Pasa de lo que queda por acreditar." },
    });
    const w = mount(NotaDeCredito, {
      props: { base: "/api/v1/app/demo", factura: null, dinero },
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    await w.setProps({ factura: { ...factura, regimen_receptor: "616" } });

    expect(w.find('[data-prueba="regimen-nota"]').exists()).toBe(false);
    await w.get("#form-nota-credito").trigger("submit");
    await flushPromises();
    expect(api.post.mock.calls[0]![1]).not.toHaveProperty("regimen_fiscal");
    expect(w.text()).toContain("Pasa de lo que queda por acreditar.");
    expect(w.emitted("hecha")).toBeUndefined();
  });

  it("un error que no es de un campo del diálogo también se muestra", async () => {
    api.post.mockRejectedValue({
      campos: { factura: "Su cancelación está en espera." },
    });
    const w = mount(NotaDeCredito, {
      props: { base: "/api/v1/app/demo", factura: null, dinero },
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    await w.setProps({ factura: { ...factura, regimen_receptor: "616" } });
    await w.get("#form-nota-credito").trigger("submit");
    await flushPromises();
    expect(w.get('[data-prueba="error-nota"]').text()).toBe(
      "Su cancelación está en espera.",
    );
  });
});
