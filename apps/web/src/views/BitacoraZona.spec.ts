import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import BitacoraView from "./BitacoraView.vue";

/*
| La bitácora muestra las horas en la zona del negocio (la de sus filtros y su CSV), no
| en la del navegador de quien mira, y dice cuál es.
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    accesoAbierto: false,
    // Distinta de la de esta máquina y de la de CI (UTC).
    zonaHoraria: "America/Bogota",
    puede: () => true,
  }),
}));

describe("bitácora en la zona del negocio", () => {
  it("las horas son del negocio y la zona se dice", async () => {
    api.get.mockResolvedValue({
      data: {
        data: [
          {
            id: "c1",
            actor: "Ana",
            accion: "oferta.archivada",
            descripcion: "Archivó Pilates",
            entidad_tipo: "oferta",
            motivo: null,
            antes: null,
            despues: null,
            // 03:30 UTC del 1 de octubre = 22:30 del 30 de septiembre en Bogotá.
            fecha: "2026-10-01T03:30:00Z",
          },
        ],
        meta: { ultima_pagina: 1, actores: [], categorias: [] },
      },
    });
    const w = mount(BitacoraView, {
      global: { plugins: [i18n], stubs: { EncabezadoSeccion: true } },
    });
    await flushPromises();

    // Con la zona del navegador saldría otra hora (o el 1 de octubre).
    expect(w.text()).toContain("30 sep, 10:30 p.m.");
    expect(w.get('[data-prueba="zona-bitacora"]').text()).toContain("UTC-5");
  });
});
