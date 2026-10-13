import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import operacion from "@/i18n/locales/operacion.es-MX";
import TareasView from "./TareasView.vue";

/*
| Asignar una tarea a alguien del equipo, con su persona, y editarla mientras está
| pendiente.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    zonaHoraria: "America/Mexico_City",
    usuario: { ulid: "yo" },
    puede: () => true,
  }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(async () => true) }));

const tarea = {
  id: "t1",
  titulo: "Revisar el tubo 6",
  detalle: null,
  estado: "pendiente",
  vence_en: "2026-10-03T05:59:59+00:00",
  persona: "Ana López",
  persona_id: "p1",
  responsable: "Leo",
  responsable_id: "leo",
  motivo: null,
  resultado: null,
  automatica: false,
  completada_en: null,
};

function montar() {
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/tareas/responsables")
        ? {
            data: [
              { id: "yo", nombre: "Constantino" },
              { id: "leo", nombre: "Leo" },
              { id: "fer", nombre: "Fernanda" },
            ],
          }
        : {
            data: [tarea],
            pendientes: 1,
            alcance: "equipo",
            meta: {
              total: 1,
              page: 1,
              per_page: 20,
              ultima_pagina: 1,
              conteos: { vencidas: 0, hoy: 0, semana: 1, sin_fecha: 0 },
            },
          },
    }),
  );
  api.post.mockResolvedValue({ data: { data: {} } });
  api.put.mockResolvedValue({ data: { data: {} } });
  return mount(TareasView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, operacion } },
        }),
      ],
      stubs: {
        EncabezadoSeccion: true,
        BuscarPersona: {
          props: ["modelValue", "personas"],
          emits: ["update:modelValue", "elegir"],
          template:
            "<button type=\"button\" data-prueba=\"elegir-persona\" @click=\"$emit('update:modelValue', 'p2'); $emit('elegir', { id: 'p2', nombre: 'Bea' })\">{{ personas.map((p) => p.nombre).join() }}</button>",
        },
        RouterLink: { props: ["to"], template: "<a><slot /></a>" },
        PanelLateral: {
          props: ["abierto", "titulo"],
          template:
            '<div v-if="abierto" data-prueba="panel"><h2>{{ titulo }}</h2><slot /><slot name="pie" /></div>',
        },
      },
    },
  });
}

beforeEach(() => vi.clearAllMocks());

describe("asignar y editar tareas", () => {
  it("crea una tarea para alguien del equipo, con su persona", async () => {
    const w = montar();
    await flushPromises();

    await w
      .findAll("button")
      .find((b) => b.text().includes("Nueva tarea"))!
      .trigger("click");
    await flushPromises();
    const panel = w.get('[data-prueba="panel"]');
    await panel.get("#t-titulo").setValue("Llamar a Bea");
    await panel.get('[data-prueba="elegir-persona"]').trigger("click");
    // A quién: el equipo, menos uno mismo («A mí»).
    const opciones = panel
      .findAll('[data-prueba="tarea-responsable"] option')
      .map((o) => o.text());
    expect(opciones).toEqual(["A mí", "Leo", "Fernanda"]);
    await panel.get('[data-prueba="tarea-responsable"]').setValue("fer");
    await panel
      .findAll("button")
      .find((b) => b.text() === "Guardar")!
      .trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith("/api/v1/app/demo/tareas", {
      titulo: "Llamar a Bea",
      detalle: null,
      vence_en: null,
      persona_id: "p2",
      responsable_id: "fer",
    });
    expect(toast.exito).toHaveBeenCalledWith("Tarea guardada");
  });

  it("edita una pendiente sin reasignarla si no cambió a quién", async () => {
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="editar-tarea"]').trigger("click");
    await flushPromises();
    const panel = w.get('[data-prueba="panel"]');
    expect(panel.text()).toContain("Editar tarea");
    // Su persona y su fecha, en el día del negocio.
    expect(panel.text()).toContain("Ana López");
    expect((panel.get("#t-vence").element as HTMLInputElement).value).toBe(
      "2026-10-02",
    );
    await panel.get("#t-titulo").setValue("Revisar los tubos 6 y 7");
    await panel
      .findAll("button")
      .find((b) => b.text() === "Guardar")!
      .trigger("click");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/tareas/t1", {
      titulo: "Revisar los tubos 6 y 7",
      detalle: null,
      vence_en: "2026-10-02",
      persona_id: "p1",
    });
  });

  it("si no se puede guardar, el error se ve dentro del panel y lo escrito se queda", async () => {
    const w = montar();
    await flushPromises();
    api.post.mockRejectedValueOnce("El título es muy largo.");

    await w
      .findAll("button")
      .find((b) => b.text().includes("Nueva tarea"))!
      .trigger("click");
    await flushPromises();
    const panel = w.get('[data-prueba="panel"]');
    expect(panel.get("#t-titulo").attributes("maxlength")).toBe("255");
    expect(panel.get("#t-detalle").attributes("maxlength")).toBe("2000");
    await panel.get("#t-titulo").setValue("Llamar a Bea");
    await panel
      .findAll("button")
      .find((b) => b.text() === "Guardar")!
      .trigger("click");
    await flushPromises();

    const abierto = w.get('[data-prueba="panel"]');
    expect(abierto.get('[data-prueba="error-tarea"]').text()).toContain(
      "El título es muy largo.",
    );
    expect((abierto.get("#t-titulo").element as HTMLInputElement).value).toBe(
      "Llamar a Bea",
    );
    // Solo en el panel: no se repite en la página de atrás.
    expect(w.text().split("El título es muy largo.")).toHaveLength(2);
  });
});
