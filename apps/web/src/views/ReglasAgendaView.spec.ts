import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import { i18n } from "@/i18n";
import type { SerieProgramada } from "@/lib/programacion";
import ReglasAgendaView from "./ReglasAgendaView.vue";

/*
| Reglas de la agenda en pestañas (políticas, cierres, programación, límites), a las
| que el menú entra por su ancla; la programación es la semana tipo, filtrable por
| actividad e instructor.
*/

const mocks = await vi.hoisted(async () => {
  const { reactive } = await import("vue");
  return {
    get: vi.fn(),
    delete: vi.fn(),
    ruta: reactive({ hash: "", query: {} as Record<string, string> }),
    sesion: { esCitas: false },
  };
});
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: vi.fn(), put: vi.fn(), delete: mocks.delete },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: () => true,
    get esCitas() {
      return mocks.sesion.esCitas;
    },
    get capacidades() {
      return { clases: !mocks.sesion.esCitas, citas: mocks.sesion.esCitas };
    },
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
vi.mock("vue-router", () => ({
  useRoute: () => mocks.ruta,
  onBeforeRouteLeave: vi.fn(),
}));

const serie = (
  id: string,
  oferta: string,
  actividad: [string, string],
  instructor: [string, string] | null,
  dias: number[],
  hora: string,
  extra: Partial<SerieProgramada> = {},
): SerieProgramada => ({
  id,
  oferta,
  actividad: actividad[1],
  actividad_id: actividad[0],
  sucursal: "Roma Norte",
  instructor: instructor?.[1] ?? null,
  instructor_id: instructor?.[0] ?? null,
  dias_semana: dias,
  hora_local: hora,
  duracion_minutos: 60,
  capacidad: 12,
  activo: true,
  vigente_desde: "2026-09-01",
  vigente_hasta: null,
  ...extra,
});

const POLE: [string, string] = ["a1", "Pole Sport"];
const YOGA: [string, string] = ["a2", "Yoga"];
const ANA: [string, string] = ["i1", "Ana"];
const BETO: [string, string] = ["i2", "Beto"];

const SERIES = [
  serie("s1", "Pole Nivel 1", POLE, ANA, [1, 3], "19:00"),
  serie("s2", "Pole Matutino", POLE, BETO, [1], "07:00"),
  serie("s3", "Yoga Flow", YOGA, ANA, [2, 4, 6], "09:00"),
  // Terminó en agosto: ya no está en la semana.
  serie("s4", "Yoga Verano", YOGA, null, [5], "10:00", {
    vigente_hasta: "2026-08-31",
  }),
];

beforeEach(() => {
  vi.clearAllMocks();
  mocks.ruta.hash = "";
  mocks.sesion.esCitas = false;
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: { data: url.endsWith("/plantillas-horario") ? SERIES : [] },
    }),
  );
});

function montar() {
  return mount(ReglasAgendaView, {
    global: { plugins: [i18n], stubs: { teleport: true } },
  });
}

const pestanas = (w: ReturnType<typeof montar>) =>
  w.get('[data-prueba="pestanas"]').findAll("button");
const activa = (w: ReturnType<typeof montar>) =>
  pestanas(w)
    .find((b) => b.attributes("aria-pressed") === "true")
    ?.text();

describe("reglas de la agenda", () => {
  it("separa cada sección en su pestaña y entra por el ancla del menú", async () => {
    mocks.ruta.hash = "#cierres";
    const w = montar();
    await flushPromises();

    expect(pestanas(w).map((b) => b.text())).toEqual([
      "Políticas",
      "Días de cierre",
      "Programación recurrente",
      "Límites y tiempos",
    ]);
    expect(activa(w)).toBe("Días de cierre");
    expect(w.text()).toContain("Agregar día");
    expect(w.text()).not.toContain("Horas de anticipación");

    // Desde el menú, otra sección sin salir de la página.
    mocks.ruta.hash = "#programacion";
    await nextTick();
    expect(activa(w)).toBe("Programación recurrente");
    expect(w.text()).not.toContain("Agregar día");
  });

  it("en citas no ofrece la programación recurrente ni la pide (ADR 0104)", async () => {
    mocks.sesion.esCitas = true;
    mocks.get.mockResolvedValue({ data: { data: [] } });
    mocks.ruta.hash = "#programacion";
    const w = montar();
    await flushPromises();

    expect(pestanas(w).map((b) => b.text())).not.toContain(
      "Programación recurrente",
    );
    expect(activa(w)).toBe("Políticas");
    expect(mocks.get).not.toHaveBeenCalledWith(
      "/api/v1/app/demo/plantillas-horario",
    );
  });

  it("muestra la semana de lunes a domingo y la filtra por actividad e instructor", async () => {
    mocks.ruta.hash = "#programacion";
    const w = montar();
    await flushPromises();

    const dia = (n: number) =>
      w
        .get(`[data-dia="${n}"]`)
        .findAll(".ps-clase")
        .map((c) => c.text());
    // El lunes, por hora.
    expect(dia(1)).toHaveLength(2);
    expect(dia(1)[0]).toContain("07:00");
    expect(dia(1)[0]).toContain("Pole Matutino");
    expect(dia(1)[1]).toContain("Pole Nivel 1");
    expect(dia(6)[0]).toContain("Yoga Flow");
    // La que terminó no está en la semana, sino aparte.
    expect(w.get('[data-dia="5"]').findAll(".ps-clase")).toHaveLength(0);
    expect(w.text()).toContain("Ya no se repiten (1)");
    expect(w.get('[data-prueba="clases-semana"]').text()).toBe(
      "6 clases a la semana",
    );

    await w.get('[data-prueba="filtro-actividad"]').setValue("a1");
    expect(dia(1)).toHaveLength(2);
    expect(w.get('[data-dia="2"]').findAll(".ps-clase")).toHaveLength(0);

    await w.get('[data-prueba="filtro-instructor"]').setValue("i2");
    expect(dia(1)).toEqual([expect.stringContaining("Pole Matutino")]);
    expect(w.get('[data-prueba="clases-semana"]').text()).toBe(
      "1 clase a la semana",
    );
  });

  it("al tocar una clase muestra su detalle y deja de repetirla", async () => {
    mocks.ruta.hash = "#programacion";
    mocks.delete.mockResolvedValue({});
    const w = montar();
    await flushPromises();

    await w.get('[data-dia="3"]').get(".ps-clase").trigger("click");
    const detalle = w.get("[role=dialog]");
    expect(detalle.text()).toContain("Pole Nivel 1");
    expect(detalle.text()).toContain("19:00–20:00");
    expect(detalle.text()).toContain("lunes, miércoles");

    await w
      .findAll("[role=dialog] button")
      .find((b) => b.text() === "Dejar de repetir")!
      .trigger("click");
    await flushPromises();
    expect(mocks.delete).toHaveBeenCalledWith(
      "/api/v1/app/demo/plantillas-horario/s1",
    );
  });
});
