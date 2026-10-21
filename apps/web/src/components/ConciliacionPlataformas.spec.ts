import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ConciliacionPlataformas from "./ConciliacionPlataformas.vue";

/*
| Conciliar pagos de Wellhub y TotalPass (ADR 0185): lo esperado contra lo recibido del
| mes y, al importar el reporte, lo que no cuadra.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Error",
  erroresPorCampo: () => ({}),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    moneda: "MXN",
    puede: () => true,
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const resumen = (extra: Record<string, unknown> = {}) => ({
  data: {
    data: {
      moneda: "MXN",
      visitas: 3,
      visitas_sin_tarifa: 0,
      esperado_minor: 27000,
      recibido_minor: 18000,
      diferencia_minor: -9000,
      importado: null,
      visitas_sin_pago: [],
      pagos_sin_visita: [],
      montos_distintos: [],
      ...extra,
    },
  },
});

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
});

describe("conciliar pagos de plataformas", () => {
  it("muestra lo esperado y, al importar, lo recibido y lo que no cuadra", async () => {
    api.get.mockResolvedValue(resumen());
    api.post.mockResolvedValueOnce(
      resumen({
        importado: { archivo: "pagos.csv", lineas: 2, fecha: null },
        visitas_sin_pago: [
          {
            id: "c3",
            fecha: "2026-09-10",
            clase: "Spinning",
            referencia: "tp-3",
          },
        ],
      }),
    );
    const w = mount(ConciliacionPlataformas, { global: { plugins: [i18n] } });
    await flushPromises();

    const cifras = w.get('[data-prueba="conciliacion-cifras"]').text();
    expect(cifras).toContain("$270.00");
    expect(cifras).toContain("Sin importar");

    const archivo = new File(["Fecha,Monto\n"], "pagos.csv", {
      type: "text/csv",
    });
    const input = w.get('[data-prueba="archivo-pagos"]');
    Object.defineProperty(input.element, "files", { value: [archivo] });
    await input.trigger("change");
    await w.get('[data-prueba="importar-pagos"]').trigger("submit");
    await flushPromises();

    const [url, datos] = api.post.mock.calls[0]!;
    expect(url).toBe("/api/v1/app/demo/plataformas/conciliacion");
    expect((datos as FormData).get("proveedor")).toBe("totalpass");
    expect(w.text()).toContain("Visitas sin pago (1)");
    expect(w.get('[data-prueba="conciliacion-cifras"]').text()).toContain(
      "$180.00",
    );
  });
});
