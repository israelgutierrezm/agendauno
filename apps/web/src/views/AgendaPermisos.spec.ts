import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import AgendaView from "./AgendaView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
// Un rol propio con solo «ver agenda».
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "a",
    puede: (p: string) => p === "agenda.ver",
    esCitas: false,
    terminologia: { sesion: "Clase", miembro: "Alumno", instructor: "Coach" },
  }),
}));

const HOY = new Date("2030-01-09T18:00:00Z");
const clase = (id: string, oferta: string, sucursal: string) => ({
  id,
  tipo: "clase",
  oferta: `Servicio ${oferta}`,
  oferta_id: oferta,
  oferta_precio_clase: null,
  instructor: "Ana",
  instructor_id: "i1",
  sala: null,
  sucursal: `Sucursal ${sucursal}`,
  sucursal_id: sucursal,
  inicia_en: "2030-01-10T17:00:00Z",
  termina_en: "2030-01-10T18:00:00Z",
  zona_horaria: "America/Mexico_City",
  capacidad: 10,
  ocupados: 2,
  en_espera: 0,
  estado: "programada",
});

function montar() {
  return mount(AgendaView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: esMX },
        }),
      ],
      stubs: {
        teleport: true,
        EncabezadoSeccion: true,
        AgendaProfesionales: true,
        AgendaClasesSemana: true,
        AgendaKpis: true,
        PanelCita: true,
        PanelNuevaCita: true,
      },
    },
  });
}

describe("agenda con solo «ver agenda»", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(HOY);
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("no pide catálogo, sucursales ni alumnos y arma los filtros con lo que ve", async () => {
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/sesiones")
            ? [clase("s1", "o1", "su1"), clase("s2", "o2", "su2")]
            : url.endsWith("/instructores")
              ? [{ id: "i1", nombre: "Ana" }]
              : [],
        },
      }),
    );
    const w = montar();
    await flushPromises();

    const pedidas = api.get.mock.calls.map((c) => String(c[0]));
    expect(pedidas.some((u) => /\/(ofertas|sucursales|miembros)$/.test(u))).toBe(
      false,
    );
    const opciones = w.findAll("option").map((o) => o.text());
    expect(opciones).toEqual(
      expect.arrayContaining([
        "Sucursal su1",
        "Sucursal su2",
        "Servicio o1",
        "Servicio o2",
        "Ana",
      ]),
    );
    expect(w.text()).not.toContain("Error");
  });

  it("si falla una lista de apoyo, el aviso se queda aunque carguen las sesiones", async () => {
    api.get.mockImplementation((url: string) =>
      url.endsWith("/instructores")
        ? Promise.reject(new Error("sin profesionales"))
        : Promise.resolve({
            data: {
              data: url.endsWith("/sesiones") ? [clase("s1", "o1", "su1")] : [],
            },
          }),
    );
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("sin profesionales");
    // Las sesiones cargaron igual.
    expect(w.findAll("option").map((o) => o.text())).toContain("Sucursal su1");
  });
});
