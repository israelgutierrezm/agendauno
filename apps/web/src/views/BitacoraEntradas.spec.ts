import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import BitacoraView from "./BitacoraView.vue";

/*
| La bitácora de entradas va por páginas: el API busca por persona, filtra por
| resultado y fechas y cuenta cuántas se permitieron y cuántas no.
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    accesoAbierto: true,
    // Sin bitácora de cambios: abre directo en las entradas.
    puede: (p: string) => p !== "auditoria.ver",
  }),
}));

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockResolvedValue({
    data: {
      data: [
        {
          id: "a1",
          persona: "Ana",
          metodo: "qr",
          permitido: false,
          codigo: "SIN_RESERVA",
          registrado_en: "2026-10-01T12:00:00Z",
        },
      ],
      meta: {
        total: 120,
        page: 1,
        per_page: 50,
        ultima_pagina: 3,
        conteos: { permitido: 110, denegado: 10 },
      },
    },
  });
});

describe("bitácora de entradas por páginas", () => {
  it("cuenta por resultado, filtra en el API y pagina", async () => {
    const w = mount(BitacoraView, {
      global: { plugins: [i18n], stubs: { EncabezadoSeccion: true } },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/accesos", {
      params: { page: 1 },
    });
    expect(w.get('[data-prueba="entradas-denegado"]').text()).toBe(
      "Denegadas (10)",
    );
    expect(w.text()).toContain("1–50 de 120");

    await w.get('[data-prueba="entradas-denegado"]').trigger("click");
    await w.get('[data-prueba="entradas-hasta"]').setValue("2026-10-01");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/accesos", {
      params: { page: 1, resultado: "denegado", hasta: "2026-10-01" },
    });
  });
});
