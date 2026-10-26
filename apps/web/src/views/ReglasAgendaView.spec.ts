import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
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
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    ruta: reactive({ hash: "", query: {} as Record<string, string> }),
    // La pestaña es el ancla: cambiarla reemplaza la dirección (sin sumar historial).
    replace: vi.fn(),
    sesion: { esCitas: false },
    confirmar: vi.fn(async () => true),
  };
});
vi.mock("@/lib/api", () => ({
  api: {
    get: mocks.get,
    post: mocks.post,
    put: mocks.put,
    delete: mocks.delete,
  },
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
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn(), info: vi.fn() }),
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: mocks.confirmar }));
vi.mock("vue-router", () => ({
  useRoute: () => mocks.ruta,
  useRouter: () => ({ replace: mocks.replace }),
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

// Cada prueba desmonta su pantalla: una montada de antes no reacciona a la siguiente.
enableAutoUnmount(afterEach);

beforeEach(() => {
  vi.clearAllMocks();
  mocks.ruta.hash = "";
  mocks.replace.mockImplementation((destino: { hash?: string }) => {
    mocks.ruta.hash = destino.hash ?? "";
    return Promise.resolve();
  });
  mocks.sesion.esCitas = false;
  // Las vigentes, para la semana; las que ya no se repiten, por páginas.
  mocks.get.mockImplementation(
    (url: string, opciones?: { params?: { estado?: string } }) => {
      if (!url.endsWith("/plantillas-horario")) {
        return Promise.resolve({ data: { data: [] } });
      }
      const terminadas = SERIES.filter((s) => s.vigente_hasta !== null);
      return Promise.resolve({
        data:
          opciones?.params?.estado === "terminadas"
            ? { data: terminadas, meta: { total: terminadas.length } }
            : { data: SERIES.filter((s) => s.vigente_hasta === null) },
      });
    },
  );
});

function montar() {
  return mount(ReglasAgendaView, {
    global: { plugins: [i18n], stubs: { teleport: true } },
  });
}

const pestanas = (w: ReturnType<typeof montar>) =>
  w.get('[data-prueba="pestanas-seccion"]').findAll("button");
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

    // Una pestaña queda en el ancla: el lateral la marca y recargar abre la misma.
    await pestanas(w)
      .find((b) => b.text() === "Días de cierre")!
      .trigger("click");
    expect(mocks.replace).toHaveBeenLastCalledWith({
      query: {},
      hash: "#cierres",
    });
    expect(activa(w)).toBe("Días de cierre");
  });

  it("quitar un día de cierre se confirma antes de abrirlo de nuevo", async () => {
    mocks.ruta.hash = "#cierres";
    mocks.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/excepciones-horario")
            ? [{ id: "d1", fecha: "2099-12-25", motivo: "Navidad" }]
            : [],
        },
      }),
    );
    mocks.delete.mockResolvedValue({});
    mocks.confirmar.mockResolvedValueOnce(false);
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="quitar-dia"]').trigger("click");
    await flushPromises();
    expect(mocks.confirmar).toHaveBeenCalledWith(
      expect.any(String),
      expect.objectContaining({ peligro: true }),
    );
    expect(mocks.delete).not.toHaveBeenCalled();

    await w.get('[data-prueba="quitar-dia"]').trigger("click");
    await flushPromises();
    expect(mocks.delete).toHaveBeenCalledWith(
      "/api/v1/app/demo/excepciones-horario/d1",
    );
  });

  it("al cerrar un día con clases dice cuántas hay y, si se pide, las cancela (ADR 0223)", async () => {
    mocks.ruta.hash = "#cierres";
    mocks.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/afectadas") ? { sesiones: 2, reservas: 3 } : [],
        },
      }),
    );
    mocks.post.mockResolvedValue({
      data: { meta: { cancelacion: { canceladas: 2 } } },
    });
    const w = montar();
    await flushPromises();

    await w.get("#ra-fecha").setValue("2099-12-24");
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/excepciones-horario/afectadas",
      { params: { fecha: "2099-12-24" } },
    );
    expect(w.get('[data-prueba="afectadas-dia"]').text()).toContain("2 clases");
    await w.get('[data-prueba="cancelar-del-dia"]').setValue(true);
    await w.get("#ra-fecha").element.form!.dispatchEvent(new Event("submit"));
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/excepciones-horario",
      expect.objectContaining({ fecha: "2099-12-24", cancelar_sesiones: true }),
    );
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
    expect(
      mocks.get.mock.calls.some((c) =>
        String(c[0]).endsWith("/plantillas-horario"),
      ),
    ).toBe(false);
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

    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/plantillas-horario",
      { params: { estado: "vigentes" } },
    );

    await w.get('[data-prueba="filtro-actividad"]').setValue("a1");
    await flushPromises();
    // Las que ya no se repiten se piden con el mismo filtro.
    expect(mocks.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/plantillas-horario",
      {
        params: {
          estado: "terminadas",
          page: 1,
          per_page: 10,
          actividad_id: "a1",
        },
      },
    );
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

  it("cancelar sus fechas desde un día dice a cuántos afecta antes de hacerlo (ADR 0223)", async () => {
    mocks.ruta.hash = "#programacion";
    mocks.post.mockImplementation(
      (_url: string, cuerpo: { previsualizar?: boolean }) =>
        Promise.resolve({
          data: {
            data: cuerpo.previsualizar
              ? { sesiones: 4, reservas: 2 }
              : { canceladas: 4 },
          },
        }),
    );
    const w = montar();
    await flushPromises();
    await w.get('[data-dia="3"]').get(".ps-clase").trigger("click");

    await w.get("#ps-cancelar-desde").setValue("2099-01-10");
    mocks.confirmar.mockResolvedValueOnce(false);
    await w.get('[data-prueba="cancelar-fechas"]').trigger("click");
    await flushPromises();
    expect(String(mocks.confirmar.mock.calls[0]![0])).toContain("4 fechas");
    expect(String(mocks.confirmar.mock.calls[0]![0])).toContain("2 personas");
    // Sin confirmar, solo se previsualizó.
    expect(mocks.post).toHaveBeenCalledTimes(1);

    await w.get('[data-prueba="cancelar-fechas"]').trigger("click");
    await flushPromises();
    expect(mocks.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/plantillas-horario/s1/terminar",
      { desde: "2099-01-10" },
    );
  });

  it("una serie sin fecha final se guarda así; con fecha, hasta ese día (ADR 0223)", async () => {
    mocks.ruta.hash = "#programacion";
    mocks.put.mockResolvedValue({ data: { data: { vigente_hasta: null } } });
    const w = montar();
    await flushPromises();
    await w.get('[data-dia="3"]').get(".ps-clase").trigger("click");

    await w.get('[data-prueba="guardar-fin"]').trigger("click");
    await flushPromises();
    expect(mocks.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/plantillas-horario/s1/fin",
      { vigente_hasta: null },
    );
  });
});
