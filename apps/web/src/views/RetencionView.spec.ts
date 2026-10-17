import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import RetencionView from "./RetencionView.vue";

/*
| Retención: la descarga lleva los mismos filtros que la tabla. Antes mandaba solo
| los días y bajaba un conjunto distinto del que se veía.
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("vue-router", () => ({
  RouterLink: { template: "<a><slot /></a>" },
  useRoute: () => ({ query: {} }),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", esCitas: false }),
}));

const miembro = {
  id: "m1",
  nombre_completo: "Ana Ruiz",
  email: "ana@correo.mx",
  estado: "por_vencer",
  vence: "2026-10-05",
  dias_restantes: 4,
  ultima_asistencia: null,
  dias_sin_asistir: null,
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.useFakeTimers();
  URL.createObjectURL = vi.fn(() => "blob:x");
  URL.revokeObjectURL = vi.fn();
  api.get.mockImplementation(
    (_url: string, opciones: { params: { formato?: string } }) =>
      Promise.resolve({
        data:
          opciones.params.formato === "csv"
            ? new Blob(["csv"])
            : {
                data: {
                  resumen: { por_vencer: 1, vencidas: 0, dias: 14 },
                  miembros: [miembro],
                  meta: { total: 1, page: 1, per_page: 25, ultima_pagina: 1 },
                },
              },
      }),
  );
});

describe("retención", () => {
  it("exporta con el estado y la búsqueda que se están viendo", async () => {
    const w = mount(RetencionView, {
      global: { plugins: [i18n], stubs: { EncabezadoSeccion: true } },
    });
    await flushPromises();

    await w.get('[data-prueba="filtro-estado"]').setValue("por_vencer");
    await w.get('[data-prueba="buscar-retencion"]').setValue("  ruiz ");
    vi.advanceTimersByTime(300);
    await flushPromises();

    await w.get('[data-prueba="exportar-retencion"]').trigger("click");
    await flushPromises();

    const llamada = api.get.mock.calls.at(-1);
    expect(llamada?.[1].params).toEqual({
      dias: 14,
      estado: "por_vencer",
      q: "ruiz",
      formato: "csv",
    });
    vi.useRealTimers();
  });

  it("sin resultados por los filtros no deja descargar", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          resumen: { por_vencer: 3, vencidas: 0, dias: 14 },
          miembros: [],
          meta: { total: 0, page: 1, per_page: 25, ultima_pagina: 1 },
        },
      },
    });
    const w = mount(RetencionView, {
      global: { plugins: [i18n], stubs: { EncabezadoSeccion: true } },
    });
    await flushPromises();

    expect(
      w.get('[data-prueba="exportar-retencion"]').attributes("disabled"),
    ).toBeDefined();
    vi.useRealTimers();
  });

  it("sin coincidencias por los filtros se dice distinto y deja quitarlos", async () => {
    const w = mount(RetencionView, {
      global: { plugins: [i18n], stubs: { EncabezadoSeccion: true } },
    });
    await flushPromises();
    expect(w.find('[data-prueba="sin-coincidencias-retencion"]').exists()).toBe(
      false,
    );

    api.get.mockResolvedValue({
      data: {
        data: {
          resumen: { por_vencer: 1, vencidas: 0, dias: 14 },
          miembros: [],
          meta: { total: 0, page: 1, per_page: 25, ultima_pagina: 1 },
        },
      },
    });
    await w.get('[data-prueba="filtro-estado"]').setValue("vencida");
    await flushPromises();
    const vacio = w.get('[data-prueba="sin-coincidencias-retencion"]');
    expect(vacio.text()).toContain("No encontramos coincidencias");

    await vacio.get("button").trigger("click");
    await flushPromises();
    expect(
      (w.get('[data-prueba="filtro-estado"]').element as HTMLSelectElement)
        .value,
    ).toBe("");
    vi.useRealTimers();
  });
});
