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
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "a",
    puede: () => true,
    esCitas: false,
    terminologia: { sesion: "Clase", miembro: "Alumno", instructor: "Coach" },
  }),
}));

// Miércoles 9 de enero de 2030: la semana va del lunes 7 al domingo 13.
const HOY = new Date("2030-01-09T18:00:00Z");
const sesion = (id: string, inicia: string, ocupados: number) => ({
  id,
  tipo: "clase",
  oferta: "Nivel 1",
  oferta_id: "o1",
  oferta_precio_clase: null,
  instructor: null,
  instructor_id: null,
  sala: null,
  inicia_en: inicia,
  termina_en: new Date(new Date(inicia).getTime() + 3_600_000).toISOString(),
  zona_horaria: "America/Mexico_City",
  capacidad: 10,
  ocupados,
  en_espera: 0,
  estado: "programada",
});

describe("indicadores de la agenda", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(HOY);
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/sesiones")
            ? [
                // Jueves 10, dentro de la semana.
                sesion("dentro", "2030-01-10T17:00:00Z", 4),
                // Lunes 14 a las 19:00 en CDMX (martes 15 en UTC): el servidor la
                // devuelve porque ensancha el rango, pero no es de esta semana.
                sesion("fuera", "2030-01-15T01:00:00Z", 10),
              ]
            : [],
        },
      }),
    );
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("cuentan solo las clases de la semana que se ve", async () => {
    const w = mount(AgendaView, {
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
          PanelCita: true,
          PanelNuevaCita: true,
          AgendaClasesSemana: {
            props: ["sesiones"],
            template:
              "<div class='semana'>{{ sesiones.map((s) => s.id).join(',') }}</div>",
          },
          AgendaKpis: {
            props: ["tarjetas"],
            template:
              "<div class='kpis'>{{ tarjetas.map((t) => t.clave + '=' + t.valor).join(' ') }}</div>",
          },
        },
      },
    });
    await flushPromises();

    expect(w.find(".semana").text()).toBe("dentro");
    const kpis = w.find(".kpis").text();
    expect(kpis).toContain("clases=1");
    expect(kpis).toContain("reservados=4");
    expect(kpis).toContain("ocupacion=40%");
  });
});
