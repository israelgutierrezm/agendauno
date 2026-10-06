import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import operacion from "@/i18n/locales/operacion.es-MX";
import ReportesView from "./ReportesView.vue";

/*
| Tendencias del dinero: dice qué representa la gráfica (cobrado neto por la fecha
| del cobro o vendido por la fecha de la compra) y nunca suma monedas: lo que haya en
| otras va aparte.
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    modalidad: "clases",
    moneda: "MXN",
    puede: () => true,
  }),
}));

const punto = (fecha: string, ventas: number, cobrado: number) => ({
  fecha,
  ventas: ventas > 0 ? 1 : 0,
  ventas_minor: ventas,
  cobrado_minor: cobrado,
  devuelto_minor: 0,
  neto_minor: cobrado,
});

const tendencias = {
  agrupacion: "dia",
  moneda: "MXN",
  serie: [punto("2026-10-01", 10000, 0), punto("2026-10-04", 0, 10000)],
  por_producto: [{ producto: "Mensual", unidades: 1, ventas_minor: 10000 }],
  totales: {
    ventas: 1,
    ventas_minor: 10000,
    cobrado_minor: 10000,
    devuelto_minor: 2500,
    neto_minor: 7500,
    ticket_promedio_minor: 10000,
  },
  otras_monedas: [
    {
      moneda: "USD",
      ventas_minor: 1000,
      cobrado_minor: 1000,
      devuelto_minor: 0,
      neto_minor: 1000,
    },
  ],
};

describe("tendencias del dinero", () => {
  it("muestra cobrado neto o vendido, cada uno con su fecha, y otras monedas aparte", async () => {
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/reportes/tendencias") ? tendencias : null,
        },
      }),
    );
    const w = mount(ReportesView, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: "es",
            missingWarn: false,
            fallbackWarn: false,
            messages: { es: { ...esMX, operacion } },
          }),
        ],
        stubs: { EncabezadoSeccion: true },
      },
    });
    await flushPromises();
    await w
      .findAll(".tu-pestanas button")
      .find((b) => b.text() === "Ingresos")!
      .trigger("click");
    await flushPromises();

    const totales = w.get('[data-prueba="tendencias-totales"]').text();
    expect(totales).toContain("$75.00");
    expect(totales).toContain("Cobrado neto");
    expect(totales).toContain("cobrado $100.00, devuelto $25.00");
    expect(totales).toContain("Vendido · 1 venta");

    expect(w.text()).toContain("por la fecha del cobro");
    await w.get('[data-prueba="metrica-ventas"]').trigger("click");
    expect(w.text()).toContain("por la fecha de la compra");

    const otras = w.get('[data-prueba="tendencias-otras-monedas"]').text();
    // En es-MX el dólar se escribe «USD 10.00» (con espacio duro).
    expect(otras).toMatch(/USD\s10\.00/);
  });
});
