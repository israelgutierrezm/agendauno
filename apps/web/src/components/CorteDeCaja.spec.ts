import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import CorteDeCaja from "./CorteDeCaja.vue";

/*
| El corte distingue «no hay movimientos en esas fechas», «nada coincide con la
| persona, el tipo o la búsqueda» y «no se pudo consultar».
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Sin conexión",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    zonaHoraria: "America/Mexico_City",
    moneda: "MXN",
    pais: "MX",
  }),
}));

const totales = {
  moneda: "MXN",
  cobrado_minor: 50000,
  devuelto_minor: 0,
  neto_minor: 50000,
  por_metodo: { efectivo: 50000 },
  por_usuario: [{ quien: "Bea", cobrado_minor: 50000, devuelto_minor: 0 }],
};
const movimiento = {
  fecha: "2026-10-01T15:00:00Z",
  tipo: "cobro",
  monto_minor: 50000,
  moneda: "MXN",
  metodo: "efectivo",
  persona: "Ana López",
  concepto: "Mensualidad",
  quien: "Bea",
  quien_id: "u1",
  referencia: "p1",
  detalle: null,
};
function respuesta(data: unknown[]) {
  return { data: { data, totales, totales_por_moneda: [totales] } };
}
function montar() {
  return mount(CorteDeCaja, { global: { plugins: [i18n] } });
}

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockResolvedValue(respuesta([movimiento]));
});

describe("estados del corte de caja", () => {
  it("una búsqueda sin resultados dice que nada coincide y se limpia", async () => {
    const w = montar();
    await flushPromises();
    expect(w.text()).toContain("Mensualidad");

    await w.get('[data-prueba="buscar-corte"]').setValue("zzz");
    const sin = w.get('[data-prueba="corte-sin-coincidencias"]');
    expect(sin.text()).toContain("No encontramos coincidencias");
    expect(w.text()).not.toContain("No hay movimientos en esas fechas.");

    await sin.get("button").trigger("click");
    expect(
      (w.get('[data-prueba="buscar-corte"]').element as HTMLInputElement).value,
    ).toBe("");
    expect(w.text()).toContain("Mensualidad");
  });

  it("filtrado por tipo y sin movimientos no es «no hay movimientos»", async () => {
    const w = montar();
    await flushPromises();
    api.get.mockResolvedValue(respuesta([]));

    await w.findAll("select")[1]!.setValue("devolucion");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/pagos/movimientos",
      expect.objectContaining({
        params: expect.objectContaining({ tipo: "devolucion" }),
      }),
    );
    expect(w.find('[data-prueba="corte-sin-coincidencias"]').exists()).toBe(
      true,
    );
    expect(w.text()).not.toContain("No hay movimientos en esas fechas.");

    // Sin filtros, el rango vacío sí dice que no hay movimientos.
    await w
      .get('[data-prueba="corte-sin-coincidencias"] button')
      .trigger("click");
    await flushPromises();
    expect(w.text()).toContain("No hay movimientos en esas fechas.");
  });

  it("si no se pudo consultar, lo dice (sin totales de otro rango) y reintenta", async () => {
    api.get.mockRejectedValueOnce(new Error("red"));
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="error-carga-corte"]').text()).toContain(
      "Sin conexión",
    );
    expect(w.text()).not.toContain("No hay movimientos en esas fechas.");
    expect(w.text()).not.toContain("$500.00");

    await w.get('[data-prueba="error-carga-corte"] button').trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="error-carga-corte"]').exists()).toBe(false);
    expect(w.text()).toContain("Mensualidad");
  });
});
