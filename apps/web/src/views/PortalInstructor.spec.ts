import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import portal from "@/i18n/locales/portal.es-MX";
import { esInstructor } from "@/lib/roles";
import InicioInstructorView from "./InicioInstructorView.vue";
import MisClasesView from "./MisClasesView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    esCitas: false,
    estudio: { nombre: "Estudio Demo" },
    usuario: { ulid: "u-coach", rol: "instructor" },
    terminologia: { sesion: "Clase" },
    puede: (p: string) => p === "agenda.ver" || p === "asistencia.marcar",
  }),
}));
const ruta = vi.hoisted(() => ({ query: {} as Record<string, string> }));
vi.mock("vue-router", () => ({
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
  useRoute: () => ruta,
}));

// Miércoles 9 de enero de 2030, 12:00 en CDMX.
const HOY = new Date("2030-01-09T18:00:00Z");
const sesion = (
  id: string,
  oferta: string,
  inicia: string,
  extra: Record<string, unknown> = {},
) => ({
  id,
  tipo: "clase",
  oferta,
  oferta_id: id,
  oferta_precio_clase: null,
  instructor: "Coach",
  instructor_id: "u-coach",
  sala: "Sala A",
  sucursal: "Roma Norte",
  inicia_en: inicia,
  termina_en: new Date(new Date(inicia).getTime() + 3_600_000).toISOString(),
  zona_horaria: "America/Mexico_City",
  capacidad: 10,
  ocupados: 4,
  en_espera: 0,
  estado: "programada",
  ...extra,
});
const SESIONES = [
  // Hoy 19:00 en CDMX.
  sesion("s1", "Pole Nivel 1", "2030-01-10T01:00:00Z", { en_espera: 2 }),
  // Hoy 20:00, cancelada: no cuenta.
  sesion("s2", "Flexibilidad", "2030-01-10T02:00:00Z", {
    estado: "cancelada",
  }),
  // Viernes.
  sesion("s3", "Exotic", "2030-01-11T17:00:00Z", { ocupados: 8 }),
];

function montar(
  componente: typeof InicioInstructorView | typeof MisClasesView,
) {
  return mount(componente, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, portal } },
        }),
      ],
      stubs: {
        teleport: true,
        PanelClase: {
          props: ["sesion"],
          template:
            "<div class='panel-clase'>{{ sesion.oferta }}<slot name='acciones' /></div>",
        },
        PanelCita: true,
        EncabezadoSeccion: {
          props: ["titulo"],
          template: "<h1>{{ titulo }}</h1>",
        },
      },
    },
  });
}

describe("portal del instructor", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(HOY);
    vi.clearAllMocks();
    localStorage.clear();
    ruta.query = {};
    api.get.mockResolvedValue({ data: { data: SESIONES } });
  });

  it("el inicio muestra lo de hoy con los pases de lista que faltan, y se actualiza", async () => {
    api.get.mockResolvedValue({
      data: {
        data: [
          // Hoy a las 10:00 en CDMX: ya empezó y faltan 3 por marcar.
          sesion("s0", "Pole Mañana", "2030-01-09T16:00:00Z", { marcadas: 1 }),
          ...SESIONES,
        ],
      },
    });
    const w = montar(InicioInstructorView);
    await flushPromises();

    const hoy = w.get('[data-prueba="hoy-instructor"]');
    const filas = hoy.findAll(".pi-hoy-fila").map((f) => f.text());
    expect(filas[0]).toContain("10:00");
    expect(filas[0]).toContain("Pole Mañana");
    expect(filas[0]).toContain("Falta pasar lista");
    // La de las 19:00 aún no empieza: no pide lista.
    expect(filas[1]).toContain("Pole Nivel 1");
    expect(filas[1]).not.toContain("Falta pasar lista");

    const antes = api.get.mock.calls.length;
    await hoy.get('[data-prueba="actualizar"]').trigger("click");
    await flushPromises();
    expect(api.get.mock.calls.length).toBeGreaterThan(antes);
  });

  it("«Próximos 7 días» abre 7 días, y lo que ya terminó hoy no es «próximo»", async () => {
    ruta.query = { vista: "lista", dias: "7" };
    api.get.mockResolvedValue({
      data: {
        data: [
          // Hoy a las 8:00 en CDMX: ya terminó.
          sesion("s0", "Mañanera", "2030-01-09T14:00:00Z"),
          ...SESIONES,
        ],
      },
    });
    const w = montar(MisClasesView);
    await flushPromises();

    const params = api.get.mock.calls.at(-1)?.[1]?.params as Record<
      string,
      string
    >;
    expect(params.desde).toBe("2030-01-09");
    expect(params.hasta).toBe("2030-01-15");
    expect(w.text()).toContain("Próximos 7 días");
    expect(w.text()).not.toContain("Mañanera");
    expect(w.text()).toContain("Exotic");
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("ve su portal quien entró como instructor (cuenta solo el rol activo)", () => {
    expect(esInstructor({ rol: "instructor" })).toBe(true);
    // Administra y también imparte: entró como admin, ve el panel; cambia de rol
    // para ver su portal.
    expect(esInstructor({ rol: "admin" })).toBe(false);
    expect(esInstructor({ rol: "recepcionista" })).toBe(false);
    expect(esInstructor(null)).toBe(false);
    // Un rol propio del negocio que imparte también abre el portal.
    expect(
      esInstructor({
        rol: "coach",
        roles_disponibles: [{ clave: "coach", faceta: "instructor" }],
      }),
    ).toBe(true);
  });

  it("el inicio pide solo lo suyo y muestra su próxima clase y sus accesos", async () => {
    const w = montar(InicioInstructorView);
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/sesiones",
      expect.objectContaining({
        params: expect.objectContaining({ instructor_id: "u-coach" }),
      }),
    );
    // El clima de su próxima clase (el del equipo, no el del alumno).
    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/clima");
    const texto = w.text();
    expect(texto).toContain("Aquí tienes tus clases y citas en Estudio Demo.");
    expect(texto).toContain("Tu próxima clase");
    expect(texto).toContain("Pole Nivel 1");
    expect(texto).toContain("4 de 10 lugares ocupados · 2 en lista de espera");
    expect(texto).toContain("Agregar a mi calendario");
    // Hoy: una clase (la cancelada no cuenta) con 4 alumnos.
    expect(texto).toContain("1 clase");
    expect(texto).toContain("4 alumnos");
    expect(texto).toContain("2 clases"); // próximos 7 días

    await w
      .findAll("button")
      .find((b) => b.text() === "Pasar lista")!
      .trigger("click");
    expect(w.find(".panel-clase").text()).toContain("Pole Nivel 1");
  });

  it("su calendario: lista, semana y el pase de lista con agregar a mi calendario", async () => {
    const w = montar(MisClasesView);
    await flushPromises();
    expect(w.text()).toContain("Próximas clases");
    expect(w.text()).toContain("Exotic");
    expect(w.text()).not.toContain("Flexibilidad");

    await w
      .findAll(".tu-segmentado button")
      .find((b) => b.text() === "Semana")!
      .trigger("click");
    await flushPromises();
    // Cada cambio de vista pide su rango.
    expect(api.get).toHaveBeenCalledTimes(2);
    const chips = w.findAll(".cv-chip-propio");
    expect(chips).toHaveLength(2);

    await chips[0].trigger("click");
    const panel = w.find(".panel-clase");
    expect(panel.text()).toContain("Pole Nivel 1");
    expect(panel.text()).toContain("Agregar a mi calendario");
  });
});
