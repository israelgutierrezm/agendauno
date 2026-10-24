import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import AgendaView from "./AgendaView.vue";

/*
| El profesional (barbero) ve «Mi agenda»: su columna y sus cifras. Las columnas de los
| demás saldrían vacías (el servidor no le manda sus citas) y se leerían como horarios
| libres. Quien ve al equipo (dueño, recepción) ve todas las columnas.
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
const quien = vi.hoisted(() => ({ rol: "instructor" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "barberia",
    usuario: {
      ulid: "p2",
      nombre: "Beto",
      rol: quien.rol,
      roles: [quien.rol],
      permisos: [],
    },
    puede: (p: string) =>
      quien.rol === "propietario" ||
      ["agenda.ver", "reservas.gestionar"].includes(p),
    esCitas: true,
    modalidad: "citas",
    zonaHoraria: "America/Mexico_City",
    terminologia: { sesion: "Cita", miembro: "Cliente", instructor: "Barbero" },
  }),
}));

const HOY = new Date("2030-01-10T15:00:00Z");

function montar() {
  return mount(AgendaView, {
    global: {
      plugins: [i18n],
      stubs: {
        teleport: true,
        EncabezadoSeccion: {
          props: ["titulo"],
          template:
            "<h1 data-prueba='titulo'>{{ titulo }}<slot name='acciones' /></h1>",
        },
        AgendaProfesionales: {
          props: ["profesionales"],
          template:
            "<div data-prueba='columnas'>{{ profesionales.map((p) => p.nombre).join(',') }}</div>",
        },
        AgendaClasesSemana: true,
        AgendaKpis: true,
        RouterLink: { template: "<a><slot /></a>" },
        PanelCita: true,
        PanelNuevaCita: true,
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(HOY);
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/instructores")
          ? [
              { id: "p1", nombre: "Sofía" },
              { id: "p2", nombre: "Beto" },
              { id: "p3", nombre: "Iván" },
            ]
          : [],
      },
    }),
  );
});
afterEach(() => {
  vi.useRealTimers();
});

describe("Mi agenda del profesional", () => {
  it("el barbero ve su columna, con el título «Mi agenda»", async () => {
    quien.rol = "instructor";
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="titulo"]').text()).toContain("Mi agenda");
    expect(w.get('[data-prueba="columnas"]').text()).toBe("Beto");
  });

  it("el dueño ve la agenda del equipo", async () => {
    quien.rol = "propietario";
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="titulo"]').text()).not.toContain("Mi agenda");
    expect(w.get('[data-prueba="columnas"]').text()).toBe("Sofía,Beto,Iván");
  });
});
