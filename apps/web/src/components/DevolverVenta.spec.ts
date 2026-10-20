import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import DevolverVenta from "./DevolverVenta.vue";

/*
| Devolver algunos productos de una venta del mostrador (ADR 0178): se eligen las
| piezas, se confirma con el monto y se manda con su llave; de una venta mixta, por qué
| forma sale el dinero.
*/

const api = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const confirmar = vi.hoisted(() =>
  vi.fn<(mensaje: string) => Promise<boolean>>(async () => true),
);
vi.mock("@/lib/confirmar", () => ({ confirmar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ pais: "MX" }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn() }),
}));

const LINEA = {
  id: "l1",
  articulo: "Pomada mate",
  cantidad: 3,
  cantidad_devuelta: 1,
  subtotal_minor: 84000,
};

function montar(partes?: { metodo: string; monto_minor: number }[]) {
  return mount(DevolverVenta, {
    props: {
      base: "/api/v1/app/demo",
      venta: { id: "v1", moneda: "MXN", lineas: [LINEA], partes },
    },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  api.post.mockResolvedValue({ data: { data: {} } });
});

describe("devolver productos", () => {
  it("dice cuántas quedan y devuelve las piezas elegidas con su llave", async () => {
    const w = montar();
    expect(w.text()).toContain("Quedan 2 por devolver");
    expect(
      w.get('[data-prueba="confirmar-devolucion"]').attributes("disabled"),
    ).toBeDefined();

    await w.get('[data-prueba="piezas-l1"]').setValue(1);
    await w.get('[data-prueba="motivo-devolucion"]').setValue("Venía abierta");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(confirmar.mock.calls[0]![0]).toContain("$280.00");
    const [url, cuerpo, opciones] = api.post.mock.calls[0]!;
    expect(url).toBe("/api/v1/app/demo/pos/ventas/v1/devoluciones");
    expect(cuerpo).toEqual({
      lineas: [{ id: "l1", cantidad: 1 }],
      motivo: "Venía abierta",
    });
    expect(opciones.headers["Idempotency-Key"]).toBeTruthy();
    expect(w.emitted("devuelto")).toHaveLength(1);
  });

  it("de una venta con varias formas pregunta por cuál sale", async () => {
    const w = montar([
      { metodo: "efectivo", monto_minor: 20000 },
      { metodo: "tarjeta", monto_minor: 36000 },
    ]);
    await w.get('[data-prueba="piezas-l1"]').setValue(1);
    await w.get('[data-prueba="motivo-devolucion"]').setValue("No le quedó");
    await w.get('[data-prueba="forma-devolucion"]').setValue("tarjeta");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post.mock.calls[0]![1]).toMatchObject({ metodo: "tarjeta" });
  });
});
