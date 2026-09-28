import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { nuevaClase } from "@/i18n/locales/gestion.es-MX";
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
    terminologia: {
      sesion: "Clase",
      miembro: "Alumno",
      instructor: "Instructor",
    },
  }),
}));

function montar() {
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/ofertas")
          ? [{ id: "o1", nombre: "Nivel 1", modalidad: "grupal" }]
          : url.endsWith("/sucursales")
            ? [
                {
                  id: "s1",
                  nombre: "Roma Norte",
                  zona_horaria: "America/Mexico_City",
                },
              ]
            : [],
      },
    }),
  );
  api.post.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/verificar")
          ? { conflictos: [] }
          : url.endsWith("/generar")
            ? { creadas: 4, omitidas: [] }
            : { id: `p${api.post.mock.calls.length}` },
      },
    }),
  );
  return mount(AgendaView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, nuevaClase } },
        }),
      ],
      stubs: {
        teleport: true,
        EncabezadoSeccion: true,
        AgendaClasesSemana: true,
        AgendaProfesionales: true,
        AgendaKpis: true,
        PanelCita: true,
        PanelNuevaCita: true,
      },
    },
  });
}

async function abrirNueva(w: ReturnType<typeof montar>) {
  await flushPromises();
  await w
    .findAll("button")
    .find((b) => b.text() === esMX.agenda.nuevaClase)!
    .trigger("click");
  await w.get("#ao").setValue("o1");
  await w.get("#as").setValue("s1");
  await w.get("#af").setValue("2030-01-07");
}

function boton(w: ReturnType<typeof montar>, texto: string) {
  return w.findAll("button").find((b) => b.text() === texto)!;
}

describe("nueva clase", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("una sola: fecha y hora por separado", async () => {
    const w = montar();
    await abrirNueva(w);
    await w.get("#ah").setValue("18:30");
    await w.get("#form-nueva-clase").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/a/sesiones",
      expect.objectContaining({ inicia_en_local: "2030-01-07 18:30:00" }),
    );
  });

  it("horario por día: una serie por cada hora distinta", async () => {
    const w = montar();
    await abrirNueva(w);
    await boton(w, nuevaClase.modos.porDia).trigger("click");
    // El 7 de enero de 2030 es lunes: el primer renglón parte de ahí.
    const primeraHora = w.findAll('input[type="time"]')[0];
    await primeraHora.setValue("18:00");
    await boton(w, nuevaClase.agregarDia).trigger("click");
    await boton(w, nuevaClase.agregarDia).trigger("click");
    const dias = w.findAll("li select");
    const horas = w.findAll('li input[type="time"]');
    await dias[1].setValue("3");
    await horas[1].setValue("18:00");
    await dias[2].setValue("6");
    await horas[2].setValue("10:00");
    await w.get("#form-nueva-clase").trigger("submit");
    await flushPromises();

    const series = api.post.mock.calls
      .filter(([url]) => url === "/api/v1/app/a/plantillas-horario")
      .map(([, cuerpo]) => [cuerpo.hora_local, cuerpo.dias_semana]);
    expect(series).toEqual([
      ["18:00", [1, 3]],
      ["10:00", [6]],
    ]);
    expect(
      api.post.mock.calls.filter(([url]) => String(url).endsWith("/generar")),
    ).toHaveLength(2);
  });

  it("no deja repetir el mismo día a la misma hora", async () => {
    const w = montar();
    await abrirNueva(w);
    await boton(w, nuevaClase.modos.porDia).trigger("click");
    await w.findAll('li input[type="time"]')[0].setValue("18:00");
    await boton(w, nuevaClase.agregarDia).trigger("click");
    await w.findAll("li select")[1].setValue("1");
    await w.findAll('li input[type="time"]')[1].setValue("18:00");

    expect(w.text()).toContain(nuevaClase.repetido);
    expect(
      boton(w, esMX.agenda.nueva.crear).attributes("disabled"),
    ).toBeDefined();
  });
});
