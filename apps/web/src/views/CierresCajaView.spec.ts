import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { computed, ref } from "vue";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import caja from "@/i18n/locales/caja.es-MX";
import CierresCajaView from "./CierresCajaView.vue";

/*
| Cobros › Cierres de caja (ADR 0175): quien supervisa ve el historial de cierres, abre
| uno con diferencia y deja su nota de revisión.
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  confirmar: vi.fn(async () => true),
  exito: vi.fn(),
  permisos: new Set<string>(["caja.operar", "caja.supervisar"]),
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post },
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: mocks.confirmar }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: mocks.exito, error: vi.fn() }),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    puede: (p: string) => mocks.permisos.has(p),
    slug: "barberia",
    moneda: "MXN",
    pais: "MX",
    zonaHoraria: "America/Mexico_City",
  }),
}));
vi.mock("@/lib/sucursalOperativa", () => ({
  useSucursales: () => ({
    lista: computed(() => [{ id: "S1", nombre: "Roma Norte" }]),
    varias: computed(() => false),
    fija: ref("S1"),
  }),
}));

const TURNO = {
  id: "T1",
  sucursal: { id: "S1", nombre: "Roma Norte" },
  estado: "abierta",
  moneda: "MXN",
  fondo_minor: 50000,
  abierta_por: "Recepción",
  abierta_en: "2026-10-01T15:00:00Z",
  cerrada_por: null,
  cerrada_en: null,
  diferencia_minor: null,
  motivo_diferencia: null,
  requiere_revision: false,
  revisada_por: null,
  revisada_en: null,
  nota_revision: null,
  esperado: null,
  conteos: [],
  movimientos: [],
};

function montar() {
  return mount(CierresCajaView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...es, caja } },
        }),
      ],
      stubs: {
        EncabezadoSeccion: {
          props: ["titulo"],
          template: "<div><h1>{{ titulo }}</h1><slot name='acciones' /></div>",
        },
        ModalDialogo: {
          props: ["abierto", "titulo"],
          template:
            "<div v-if='abierto'><h2>{{ titulo }}</h2><slot /><slot name='pie' /></div>",
        },
        PaginacionListado: true,
        RouterLink: {
          props: ["to"],
          template: "<a :data-to='JSON.stringify(to)'><slot /></a>",
        },
      },
    },
  });
}

describe("cierres de caja", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("lista los cierres, abre uno con diferencia y lo revisa", async () => {
    const cierre = {
      ...TURNO,
      id: "T0",
      estado: "cerrada",
      diferencia_minor: -5000,
      requiere_revision: true,
      motivo_diferencia: "Di mal un cambio",
    };
    mocks.get
      .mockResolvedValueOnce({
        data: {
          data: [cierre],
          meta: { page: 1, ultima_pagina: 1, total: 1, per_page: 20 },
        },
      })
      .mockResolvedValueOnce({ data: { data: cierre } });
    mocks.post.mockResolvedValueOnce({
      data: {
        data: {
          ...cierre,
          revisada_por: "Dueña",
          revisada_en: "2026-10-01T20:00:00Z",
          nota_revision: "Se habló con recepción",
        },
      },
    });
    const w = montar();
    await flushPromises();

    expect(w.get("h1").text()).toBe("Cierres de caja");
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/barberia/caja/turnos",
      expect.objectContaining({ params: expect.objectContaining({ page: 1 }) }),
    );
    expect(w.get('[data-prueba="cierres"]').text()).toContain("Por revisar");

    await w.get('[data-prueba="cierres"] tbody tr').trigger("click");
    await flushPromises();
    expect(w.get('[data-prueba="detalle-cierre"]').text()).toContain(
      "Di mal un cambio",
    );
    await w
      .get('[data-prueba="nota-revision"]')
      .setValue("Se habló con recepción");
    await w.get("#caja-revision").trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/barberia/caja/turnos/T0/revision",
      { nota: "Se habló con recepción" },
    );
    expect(w.get('[data-prueba="detalle-cierre"]').text()).toContain(
      "Revisada por Dueña",
    );
  });
});
