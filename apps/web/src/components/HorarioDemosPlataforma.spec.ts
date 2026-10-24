import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import { hayCambiosPendientes } from "@/lib/cambiosPendientes";
import HorarioDemosPlataforma from "./HorarioDemosPlataforma.vue";

/*
| Superadmin › Demos › Horario (ADR 0204): el horario de cada día (uno o varios rangos),
| los días bloqueados, la duración y el cupo; guardar envía todo junto y salir con
| cambios pregunta antes.
*/

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("axios", () => ({ default: { create: () => http } }));
const erroresDelServidor = vi.hoisted(() => ({
  valor: {} as Record<string, string>,
}));
vi.mock("@/lib/api", () => ({
  mensajeDeError: () => "Error",
  erroresPorCampo: () => erroresDelServidor.valor,
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));

const LIMITE = { minimo: 1, maximo: 60 };
const HORARIO = {
  activas: true,
  zona: "America/Mexico_City",
  minutos: 15,
  por_horario: 1,
  dias_adelante: 10,
  horas_anticipacion: 3,
  rangos: [{ dia: 1, desde: "10:00", hasta: "12:00" }],
  bloqueos: [],
  limites: {
    minutos: LIMITE,
    por_horario: LIMITE,
    dias_adelante: LIMITE,
    horas_anticipacion: LIMITE,
  },
};

function montar() {
  return mount(HorarioDemosPlataforma, {
    props: { apiUrl: "http://api", token: "tk" },
    global: { plugins: [i18n] },
  });
}

enableAutoUnmount(afterEach);
beforeEach(() => {
  vi.clearAllMocks();
  erroresDelServidor.valor = {};
  http.get.mockResolvedValue({ data: { data: HORARIO } });
  http.put.mockImplementation((_url: string, cuerpo: typeof HORARIO) =>
    Promise.resolve({ data: { data: { ...HORARIO, ...cuerpo } } }),
  );
});

describe("HorarioDemosPlataforma", () => {
  it("carga el horario y cuenta cuántas demos caben a la semana", async () => {
    const w = montar();
    await flushPromises();
    // El lunes de 10 a 12, cada 15 minutos: 8 demos.
    expect(w.get('[data-dia="1"]').findAll("input[type=time]")).toHaveLength(2);
    expect(w.get('[data-prueba="demos-por-semana"]').text()).toBe(
      "Hasta 8 demos a la semana",
    );
    expect(w.get('[data-dia="2"]').text()).toContain("Sin demos");
    expect(hayCambiosPendientes()).toBe(false);
  });

  it("copia el lunes a los demás días hábiles, bloquea un día y guarda todo junto", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="copiar-lunes"]').trigger("click");
    await w.get('[data-prueba="agregar-rango-6"]').trigger("click");
    expect(hayCambiosPendientes()).toBe(true);

    await w.get("#demos-bloqueo-fecha").setValue("2099-12-24");
    await w.get("#demos-bloqueo-motivo").setValue("Nochebuena");
    await w.get('[data-prueba="bloquear-dia"]').trigger("click");
    expect(w.get('[data-bloqueo="2099-12-24"]').text()).toContain("Nochebuena");

    await w.get('[data-prueba="horario-demos"]').trigger("submit");
    await flushPromises();
    const [url, cuerpo] = http.put.mock.calls[0]!;
    expect(url).toBe("/api/v1/plataforma/demos/horario");
    expect(cuerpo.rangos).toEqual([
      { dia: 1, desde: "10:00", hasta: "12:00" },
      { dia: 2, desde: "10:00", hasta: "12:00" },
      { dia: 3, desde: "10:00", hasta: "12:00" },
      { dia: 4, desde: "10:00", hasta: "12:00" },
      { dia: 5, desde: "10:00", hasta: "12:00" },
      { dia: 6, desde: "10:00", hasta: "14:00" },
    ]);
    expect(cuerpo.bloqueos).toEqual([
      { fecha: "2099-12-24", motivo: "Nochebuena" },
    ]);
    expect(cuerpo).toMatchObject({ activas: true, minutos: 15 });
    expect(w.emitted("guardado")).toHaveLength(1);
    expect(hayCambiosPendientes()).toBe(false);
  });

  it("no bloquea un día pasado y dice junto al rango lo que rechazó el servidor", async () => {
    const w = montar();
    await flushPromises();
    await w.get("#demos-bloqueo-fecha").setValue("2000-01-01");
    await w.get('[data-prueba="bloquear-dia"]').trigger("click");
    expect(w.text()).toContain("Elige un día de hoy en adelante.");

    await w.get('[data-prueba="agregar-rango-1"]').trigger("click");
    http.put.mockRejectedValueOnce(new Error("422"));
    erroresDelServidor.valor = {
      "rangos.1.desde":
        "Dos horarios del mismo día se enciman: júntalos en uno.",
    };
    await w.get('[data-prueba="horario-demos"]').trigger("submit");
    await flushPromises();
    expect(w.get('[data-rango="1-1"]').text()).toContain("se enciman");
  });
});
