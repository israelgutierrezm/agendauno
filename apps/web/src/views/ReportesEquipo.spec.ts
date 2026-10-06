import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import operacion from "@/i18n/locales/operacion.es-MX";
import ReportesView from "./ReportesView.vue";

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
const sesion = vi.hoisted(() => ({
  slug: "demo",
  modalidad: "citas",
  puede: () => true,
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

const cifras = {
  clases: 0,
  citas: 12,
  agendado_min: 600,
  disponible_min: 900,
  agendado_en_horario_min: 600,
  presentes: 10,
  ausentes: 2,
  canceladas: 1,
  ingreso_minor: 300000,
  costo_minor: 120000,
  margen_minor: 180000,
  ocupacion_pct: 67,
  inasistencia_pct: 17,
};

const respuestas: Record<string, unknown> = {
  "/reportes/negocio": {
    moneda: "MXN",
    ingresos_minor: 300000,
    ordenes_pagadas: 10,
    clases: 12,
    ocupacion_pct: 100,
    ocupacion_agenda_pct: 67,
    no_show_pct: 17,
    alumnos_activos: 9,
    arpu_minor: 33333,
  },
  "/reportes/equipo": {
    moneda: "MXN",
    totales: cifras,
    profesionales: [
      { ...cifras, id: "u1", nombre: "Carlos Ruiz", foto_url: null },
      {
        ...cifras,
        id: "u2",
        nombre: "Ana Díaz",
        foto_url: null,
        citas: 0,
        disponible_min: 0,
        agendado_en_horario_min: 0,
        ocupacion_pct: null,
      },
    ],
  },
  "/reportes/demanda": {
    totales: {
      sesiones: 12,
      capacidad: 12,
      confirmadas: 12,
      espera: 0,
      ocupacion_pct: 100,
      disponible_min: 900,
      agendado_min: 600,
      utilizacion_pct: 67,
    },
    matriz: [
      {
        dia: 1,
        hora: 10,
        sesiones: 1,
        capacidad: 1,
        confirmadas: 1,
        espera: 0,
        ocupacion_pct: 100,
        disponible_min: 120,
        agendado_min: 60,
        utilizacion_pct: 50,
      },
      {
        dia: 2,
        hora: 9,
        sesiones: 0,
        capacidad: 0,
        confirmadas: 0,
        espera: 0,
        ocupacion_pct: null,
        disponible_min: 60,
        agendado_min: 0,
        utilizacion_pct: 0,
      },
    ],
    actividades: [],
  },
};

function montar() {
  api.get.mockImplementation((url: string) => {
    const clave = Object.keys(respuestas).find((k) => url.endsWith(k));
    return Promise.resolve({
      data: {
        data: clave
          ? respuestas[clave]
          : url.endsWith("/reportes/sucursales")
            ? {
                sucursales: [],
                sin_sucursal: { miembros_activos: 0 },
                totales: {
                  sucursales: 0,
                  miembros_activos: 0,
                  sesiones_proximas: 0,
                },
              }
            : null,
      },
    });
  });
  return mount(ReportesView, {
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
}

async function irA(
  w: ReturnType<typeof montar>,
  pestana: string,
): Promise<void> {
  await w
    .findAll(".tu-pestanas button")
    .find((b) => b.text() === pestana)!
    .trigger("click");
  // Cada pestaña pide lo suyo al abrirse.
  await flushPromises();
}

beforeEach(() => {
  vi.clearAllMocks();
  sesion.modalidad = "citas";
});

describe("agenda del equipo y ocupación por agenda", () => {
  it("lista a cada quien con su ocupación, citas, inasistencias, valor, pago y margen", async () => {
    const w = montar();
    await flushPromises();
    await irA(w, "Equipo y sucursales");

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/reportes/equipo", {
      params: expect.objectContaining({ desde: expect.any(String) }),
    });
    const filas = w.findAll('[data-prueba="profesional"]');
    const carlos = filas[0].text();
    expect(carlos).toContain("Carlos Ruiz");
    expect(carlos).toContain("67%");
    expect(carlos).toContain("10 h / 15 h");
    expect(carlos).toContain("12 citas");
    expect(carlos).toContain("17%");
    expect(carlos).toContain("$3,000.00");
    expect(carlos).toContain("$1,800.00");
    // Quien no tiene horario de atención no tiene ocupación.
    expect(filas[1].text()).toContain("Sin horario");
  });

  it("en citas, el resumen y el mapa miden la agenda y no el cupo", async () => {
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("67%");
    expect(w.text()).not.toContain("100%");

    await irA(w, "Ocupación");
    expect(w.get('[data-prueba="resumen-agenda"]').text()).toContain("67%");
    expect(w.get('[data-prueba="celda-1-10"]').text()).toBe("50%");
    // La franja con horario y sin nada agendado también aparece.
    expect(w.get('[data-prueba="celda-2-9"]').text()).toBe("0%");
  });

  it("en clases, el mapa sigue midiendo el cupo", async () => {
    sesion.modalidad = "clases";
    const w = montar();
    await flushPromises();
    await irA(w, "Ocupación");

    expect(w.get('[data-prueba="celda-1-10"]').text()).toBe("100%");
    expect(w.find('[data-prueba="celda-2-9"]').exists()).toBe(false);
    expect(w.find('[data-prueba="resumen-agenda"]').exists()).toBe(false);
  });
});
