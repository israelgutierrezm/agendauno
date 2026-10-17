import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import AgendaView from "./AgendaView.vue";

/*
| La lista del día (teléfono y vista Día) distingue «sin clases este día», «ninguna
| coincide con los filtros» y «no se pudo cargar la agenda».
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "a",
    puede: () => true,
    esCitas: false,
    terminologia: { sesion: "Clase", miembro: "Alumno", instructor: "Coach" },
  }),
}));

// Miércoles 9 de enero de 2030.
const HOY = new Date("2030-01-09T18:00:00Z");
const clase = {
  id: "s1",
  tipo: "clase",
  oferta: "Nivel 1",
  oferta_id: "o1",
  oferta_precio_clase: null,
  instructor: null,
  instructor_id: null,
  sala: null,
  // Hoy a mediodía en la Ciudad de México.
  inicia_en: "2030-01-09T18:00:00Z",
  termina_en: "2030-01-09T19:00:00Z",
  zona_horaria: "America/Mexico_City",
  capacidad: 10,
  ocupados: 4,
  en_espera: 0,
  estado: "programada",
};
const ofertas = [
  { id: "o1", nombre: "Nivel 1", archivado: false },
  { id: "o2", nombre: "Nivel 2", archivado: false },
];

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
        EncabezadoSeccion: {
          template: "<div><slot name='acciones' /></div>",
        },
        AgendaProfesionales: true,
        PanelCita: true,
        PanelNuevaCita: true,
        AgendaClasesSemana: true,
        AgendaKpis: true,
      },
    },
  });
}

describe("lista del día en la agenda", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(HOY);
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/sesiones")
            ? [clase]
            : url.endsWith("/ofertas")
              ? ofertas
              : [],
        },
      }),
    );
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("con un filtro que deja el día sin clases, dice que nada coincide y lo quita", async () => {
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="dia-vacio"]').exists()).toBe(false);

    await w.get('[data-prueba="mostrar-filtros"]').trigger("click");
    // El de la clase (antes puede ir el de la sucursal).
    await w
      .findAll('[data-prueba="filtros-agenda"] select')
      .find((s) => s.find('option[value="o2"]').exists())!
      .setValue("o2");

    const sin = w.get('[data-prueba="dia-sin-coincidencias"]');
    expect(sin.text()).toContain(esMX.tabla.sinCoincidencias);
    expect(w.find('[data-prueba="dia-vacio"]').exists()).toBe(false);

    await sin.get("button").trigger("click");
    expect(w.find('[data-prueba="dia-sin-coincidencias"]').exists()).toBe(
      false,
    );
    expect(w.find('[data-prueba="dia-vacio"]').exists()).toBe(false);
  });

  it("si las clases no se pudieron consultar, no dice «sin clases este día»", async () => {
    const base = api.get.getMockImplementation()!;
    api.get.mockImplementation((url: string, config?: unknown) =>
      url.endsWith("/sesiones")
        ? Promise.reject(new Error("Sin conexión"))
        : base(url, config),
    );
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="dia-error"]').text()).toContain(
      esMX.agenda.errorCarga,
    );
    expect(w.find('[data-prueba="dia-vacio"]').exists()).toBe(false);
    expect(w.text()).not.toContain(esMX.agenda.sinClasesDia);

    api.get.mockImplementation(base);
    await w.get('[data-prueba="dia-error"] button').trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="dia-error"]').exists()).toBe(false);
  });

  it("un día sin clases y sin filtros lo dice", async () => {
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: { data: url.endsWith("/ofertas") ? ofertas : [] },
      }),
    );
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="dia-vacio"]').text()).toBe(
      esMX.agenda.sinClasesDia,
    );
  });
});
