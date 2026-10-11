import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import CalendarioPublico from "./CalendarioPublico.vue";

/*
| Calendario público de clases (M02): la semana con sus días, el primero con clases a la
| vista, los lugares por la regla única de cupo y, sin clases, el salto a la siguiente
| fecha. Elegir una clase avisa a la página con esa clase.
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api }));

const ZONA = "America/Mexico_City";
function sesion(id: string, inicia: string, libres: number | null) {
  return {
    id,
    clase: "Pole Fit",
    actividad: { id: "a1", nombre: "Pole" },
    sucursal: { id: "s1", nombre: "Roma" },
    instructor: "Diana",
    inicia_en: inicia,
    termina_en: inicia,
    zona_horaria: ZONA,
    duracion_minutos: 60,
    capacidad: 8,
    lugares_libres: libres,
  };
}
function respuesta(sesiones: unknown[], siguiente: string | null = null) {
  return {
    data: {
      data: {
        desde: "2026-10-01",
        hasta: "2026-10-07",
        zona_horaria: ZONA,
        sesiones,
        filtros: {
          sucursales: [{ id: "s1", nombre: "Roma" }],
          actividades: [{ id: "a1", nombre: "Pole" }],
          instructores: [{ id: "i1", nombre: "Diana" }],
        },
        siguiente,
      },
    },
  };
}

beforeEach(() => api.get.mockReset());

describe("CalendarioPublico", () => {
  it("muestra el primer día con clases y sus lugares; pedir lugar avisa con la clase", async () => {
    api.get.mockResolvedValueOnce(
      respuesta([
        sesion("01a", "2026-10-03T01:00:00Z", 3), // 2 de octubre, 19:00 local
        sesion("01b", "2026-10-03T02:00:00Z", 0), // llena
      ]),
    );
    const w = mount(CalendarioPublico, {
      props: { slug: "fluo", puedePedir: true },
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/fluo/calendario",
      expect.objectContaining({ params: expect.objectContaining({ dias: 7 }) }),
    );
    expect(w.findAll('[role="tab"]')).toHaveLength(7);
    expect(w.get('[role="tab"][aria-selected="true"]').text()).toContain("2");
    const clases = w.findAll('[data-prueba="clase-calendario"]');
    expect(clases).toHaveLength(2);
    expect(clases[0]!.text()).toContain("3 de 8 lugares");
    expect(clases[1]!.text()).toContain("Llena");
    // Una clase llena no se pide; con un solo filtro de cada cosa, no hay filtros.
    expect(w.findAll('[data-prueba="pedir-lugar"]')).toHaveLength(1);
    expect(w.find("select").exists()).toBe(false);

    await w.get('[data-prueba="pedir-lugar"]').trigger("click");
    const evento = w.emitted("pedir")?.[0]?.[0] as {
      sesion: string;
      etiqueta: string;
    };
    expect(evento.sesion).toBe("01a");
    expect(evento.etiqueta).toContain("Pole Fit");
  });

  it("una semana sin clases ofrece la siguiente fecha y la carga", async () => {
    api.get.mockResolvedValueOnce(respuesta([], "2026-10-20"));
    api.get.mockResolvedValueOnce(respuesta([]));
    const w = mount(CalendarioPublico, {
      props: { slug: "fluo" },
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(w.text()).toContain("No hay clases este día.");
    const siguiente = w
      .findAll("button")
      .find((b) => b.text().includes("Siguiente fecha"));
    expect(siguiente).toBeDefined();
    await siguiente!.trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/fluo/calendario",
      expect.objectContaining({
        params: expect.objectContaining({ desde: "2026-10-20" }),
      }),
    );
  });
});
