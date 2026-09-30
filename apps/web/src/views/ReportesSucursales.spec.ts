import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import operacion from "@/i18n/locales/operacion.es-MX";
import ReportesView from "./ReportesView.vue";

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));

describe("reporte por sucursal", () => {
  it("lee las sucursales, quienes no tienen una y los totales", async () => {
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          // Solo importa el reporte por sucursal; las demás secciones, vacías.
          data: url.endsWith("/reportes/sucursales")
            ? {
                sucursales: [
                  {
                    id: "s1",
                    nombre: "Roma Norte",
                    region: "CDMX",
                    moneda: "MXN",
                    miembros_activos: 12,
                    sesiones_proximas: 5,
                  },
                  {
                    id: "s2",
                    nombre: "Condesa",
                    region: null,
                    moneda: "MXN",
                    miembros_activos: 3,
                    sesiones_proximas: 2,
                  },
                ],
                sin_sucursal: { miembros_activos: 4 },
                totales: {
                  sucursales: 2,
                  miembros_activos: 19,
                  sesiones_proximas: 7,
                },
              }
            : null,
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

    // El periodo aplica a la agenda del equipo; las sucursales son el estado actual.
    await w
      .findAll(".tu-segmentado button")
      .find((b) => b.text() === "Equipo y sucursales")!
      .trigger("click");
    expect(w.find('input[type="date"]').exists()).toBe(true);
    expect(w.text()).toContain("Estado actual");

    const filas = w
      .findAll("table")
      .find((t) => t.text().includes("Roma Norte"))!
      .findAll("tbody tr")
      .map((tr) => tr.findAll("td").map((td) => td.text()));
    expect(filas).toEqual([
      ["Roma Norte", "CDMX", "MXN", "12", "5"],
      ["Condesa", "—", "MXN", "3", "2"],
      ["Sin sucursal asignada", "", "", "4", "—"],
    ]);
    const total = w
      .find("tfoot")
      .findAll("td")
      .map((td) => td.text());
    expect(total).toEqual(["Total", "", "", "19", "7"]);
  });
});
