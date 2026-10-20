import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import RegistrarPagoOrden from "./RegistrarPagoOrden.vue";

/*
| Registrar en caja lo que se debe. Una cuota que lleva la orden (inscripción,
| anualidad, penalización; B04) la exenta quien puede, con motivo, y lo que se cobra
| baja; si era lo único, ya no hay nada que cobrar.
*/

const api = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const sesion = vi.hoisted(() => ({ puede: vi.fn(() => true) }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

const orden = {
  id: "o1",
  persona: "Ana",
  concepto: "Mensual 8, Inscripción",
  total_minor: 150000,
  moneda: "MXN",
  cuotas: [
    {
      id: "l2",
      concepto: "inscripcion",
      nombre: "Inscripción",
      monto_minor: 50000,
    },
  ],
};

function montar(o = orden) {
  return mount(RegistrarPagoOrden, {
    props: { base: "/api/v1/app/demo", orden: o },
    global: { plugins: [i18n], stubs: { teleport: true } },
  });
}

beforeEach(() => {
  api.post.mockReset();
  sesion.puede.mockReturnValue(true);
});

describe("registrar pago con cuotas", () => {
  it("exentar una cuota pide motivo y baja lo que se cobra", async () => {
    api.post.mockResolvedValueOnce({
      data: { data: { estado: "pendiente", total_minor: 100000 } },
    });
    const w = montar();
    expect(w.text()).toContain("$1,500.00");

    await w.get('[data-prueba="exentar-cuota"]').trigger("click");
    expect(
      w.get('[data-prueba="confirmar-exencion"]').attributes("disabled"),
    ).toBeDefined();
    await w.get('[data-prueba="motivo-cuota"]').setValue("Reingreso pactado");
    await w.get('[data-prueba="confirmar-exencion"]').trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/ordenes/o1/lineas/l2/exentar",
      { motivo: "Reingreso pactado" },
    );
    expect(w.text()).toContain("$1,000.00");
    expect(w.find('[data-prueba="cuotas-orden"]').exists()).toBe(false);
  });

  it("cerrar después de exentar avisa el cambio (quien lo abrió recarga)", async () => {
    api.post.mockResolvedValueOnce({
      data: { data: { estado: "pendiente", total_minor: 100000 } },
    });
    const w = montar();
    await w.get('[data-prueba="exentar-cuota"]').trigger("click");
    await w.get('[data-prueba="motivo-cuota"]').setValue("Reingreso pactado");
    await w.get('[data-prueba="confirmar-exencion"]').trigger("click");
    await flushPromises();

    await w
      .findAll("button")
      .find((b) => b.text() === "Cancelar")!
      .trigger("click");
    expect(w.emitted("cerrar")).toBeUndefined();
    expect(w.emitted("registrado")?.[0]).toEqual([
      "Se exentó «Inscripción»: la orden queda en $1,000.00.",
    ]);
  });

  it("sin permiso no se ofrece exentar", () => {
    sesion.puede.mockReturnValue(false);
    const w = montar();
    expect(w.text()).toContain("Inscripción");
    expect(w.find('[data-prueba="exentar-cuota"]').exists()).toBe(false);
  });
});
