import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ReportePlataformasView from "./ReportePlataformasView.vue";

/*
| Reportes → Plataformas (ADR 0148): los visitantes de Wellhub y TotalPass y sus
| visitas, aparte de los alumnos.
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "fluo",
    zonaHoraria: "America/Mexico_City",
  }),
}));

const resumen = {
  resumen: {
    visitas: 12,
    validadas: 10,
    sin_verificar: 1,
    excepciones: 1,
    automaticas: 9,
    sin_clase: 2,
    visitantes: 7,
    nuevos: 3,
  },
  reservas: {
    total: 6,
    por_venir: 2,
    asistieron: 2,
    canceladas: 1,
    canceladas_tarde: 1,
    no_asistieron: 0,
    rechazadas: 0,
  },
  por_proveedor: [
    { proveedor: "wellhub", visitas: 8, visitantes: 5, reservas: 6 },
    { proveedor: "totalpass", visitas: 4, visitantes: 2, reservas: 0 },
  ],
  por_dia: [
    { fecha: "2026-10-01", visitas: 5 },
    { fecha: "2026-10-02", visitas: 7 },
  ],
  por_clase: [{ clase: "Pole Sport", visitas: 6 }],
};
const visitante = {
  id: "v1",
  proveedor: "wellhub",
  nombre: "Ana Pérez",
  email: "ana@correo.mx",
  telefono: "5512345678",
  producto: "Plan Gold",
  id_externo: "1234567890123",
  visitas: 4,
  primera_visita_en: "2026-09-02T14:00:00Z",
  ultima_visita_en: "2026-10-01T12:00:00Z",
  es_alumno: true,
};

function montar() {
  return mount(ReportePlataformasView, {
    global: {
      plugins: [i18n],
      stubs: {
        PanelLateral: {
          props: ["abierto", "titulo"],
          template:
            '<div v-if="abierto" data-prueba="panel"><h2>{{ titulo }}</h2><slot /></div>',
        },
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) => {
    if (url.endsWith("/reportes/plataformas")) {
      return Promise.resolve({ data: { data: resumen } });
    }
    if (url.endsWith("/visitantes-plataforma/v1")) {
      return Promise.resolve({
        data: {
          data: {
            ...visitante,
            historial: [
              {
                id: "c1",
                fecha: "2026-10-01T12:00:00Z",
                clase: "Pole Sport",
                sesion_inicia_en: "2026-10-01T12:10:00Z",
                estado: "validado",
                origen: "automatico",
              },
            ],
            reservas: [
              {
                id: "l1",
                clase: "Pole Sport",
                sesion_inicia_en: "2026-10-02T14:00:00Z",
                estado: "reservado",
              },
            ],
          },
        },
      });
    }
    return Promise.resolve({
      data: {
        data: [visitante],
        meta: { total: 1, page: 1, per_page: 25, ultima_pagina: 1 },
      },
    });
  });
});

describe("reportes: plataformas", () => {
  it("resume el periodo y lista a los visitantes, aparte de los alumnos", async () => {
    const w = montar();
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/fluo/reportes/plataformas",
      { params: expect.objectContaining({ desde: expect.any(String) }) },
    );
    expect(w.get('[data-prueba="plataformas-visitas"]').text()).toContain("12");
    expect(w.get('[data-prueba="plataformas-visitas"]').text()).toContain(
      "9 automáticas",
    );
    expect(w.get('[data-prueba="plataformas-sinClase"]').classes()).toContain(
      "rp-aviso",
    );
    expect(w.text()).toContain("Pole Sport");

    const fila = w.get('[data-prueba="visitante"]');
    expect(fila.text()).toContain("Ana Pérez");
    expect(fila.text()).toContain("Wellhub");
    expect(w.get('[data-prueba="es-alumno"]').text()).toBe("Ya es alumno");

    // Las reservas desde la app (ADR 0149): canceladas suma las tardías.
    const reservas = w.get('[data-prueba="reservas-app"]').text();
    expect(reservas).toContain("2 por venir");
    expect(reservas).toContain("1 tarde");
    expect(w.text()).toContain("6 reservas");
  });

  it("filtra por plataforma el resumen y la lista", async () => {
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="plataforma-totalpass"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/fluo/reportes/plataformas",
      { params: expect.objectContaining({ proveedor: "totalpass" }) },
    );
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/fluo/visitantes-plataforma",
      { params: { page: 1, proveedor: "totalpass" } },
    );
  });

  it("abre a un visitante con sus visitas", async () => {
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="visitante"]').trigger("click");
    await flushPromises();
    const panel = w.get('[data-prueba="panel"]');
    expect(panel.text()).toContain("1234567890123");
    expect(panel.text()).toContain("Plan Gold");
    expect(w.get('[data-prueba="historial-visitante"]').text()).toContain(
      "Automática",
    );
    expect(w.get('[data-prueba="reservas-visitante"]').text()).toContain(
      "Reservada",
    );
  });
});
