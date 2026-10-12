import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import CampanasNegocio from "./CampanasNegocio.vue";

/*
| El reporte por campaña del negocio (ADR 0144): cada campaña con sus solicitudes,
| compradores nuevos, compras, devoluciones y primeras reservas; las renovaciones aparte
| y lo que no trae campaña, «Sin atribución».
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));

const fila = (campana: unknown, extra: Record<string, number> = {}) => ({
  campana,
  solicitudes: 0,
  nuevos_compradores: 0,
  compras: 0,
  compras_minor: 0,
  devoluciones: 0,
  devoluciones_minor: 0,
  primeras_reservas: 0,
  renovaciones: 0,
  renovaciones_minor: 0,
  ...extra,
});

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockResolvedValue({
    data: {
      data: {
        moneda: "MXN",
        filas: [
          fila(
            {
              campana: "octubre",
              fuente: "instagram",
              medio: "social",
              referente: null,
            },
            {
              solicitudes: 3,
              nuevos_compradores: 1,
              compras: 1,
              compras_minor: 89900,
              renovaciones: 2,
              renovaciones_minor: 179800,
            },
          ),
          fila(null, {
            compras: 1,
            compras_minor: 89900,
            devoluciones: 1,
            devoluciones_minor: 89900,
          }),
        ],
      },
    },
  });
});

describe("reporte por campaña", () => {
  it("cada campaña con sus cifras; las renovaciones aparte y sin campaña, «Sin atribución»", async () => {
    const w = mount(CampanasNegocio, {
      props: { desde: "2026-09-12", hasta: "2026-10-11" },
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/reportes/campanas", {
      params: { desde: "2026-09-12", hasta: "2026-10-11" },
    });
    const filas = w.findAll('[data-prueba="fila-campana"]');
    expect(filas[0]!.text()).toContain("octubre · instagram / social");
    expect(filas[0]!.text()).toContain("Solicitudes3");
    expect(filas[0]!.text()).toContain("Renovaciones (aparte)2 · $1,798.00");
    expect(filas[1]!.text()).toContain("Sin atribución");
    expect(filas[1]!.text()).toContain("Devoluciones1 · $899.00");
    expect(w.text()).toContain("no se calcula costo por resultado");
  });
});
