import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { listados, profesional } from "@/i18n/locales/equipo.es-MX";
import { tarjetas } from "@/i18n/locales/gestion.es-MX";
import InstructoresView from "./InstructoresView.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: vi.fn() },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "estudio-a",
    puede: () => true,
    terminologia: {
      sesion: "Clase",
      miembro: "Alumno",
      instructor: "Instructor",
    },
  }),
}));
vi.mock("vue-router", () => ({
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
}));

const equipo = [
  {
    id: "i1",
    nombre: "Beto Instructor",
    nombre_corto: "Beto Instructor",
    foto_url: null,
    resumen: {
      proxima: {
        inicia_en: "2030-01-06T16:00:00Z",
        zona_horaria: "America/Mexico_City",
        clase: "Nivel 1",
        tipo: "clase",
      },
      semana: { clases: 2, citas: 5 },
      sedes: ["Condesa", "Roma Norte"],
      resenas: { promedio: 4.75, total: 12 },
    },
  },
  {
    id: "i2",
    nombre: "Sofía Profesional",
    nombre_corto: "Sofía Profesional",
    foto_url: null,
    resumen: {
      proxima: null,
      semana: { clases: 0, citas: 0 },
      sedes: [],
      resenas: null,
    },
  },
];

describe("equipo en cuadrícula", () => {
  beforeEach(() => {
    localStorage.clear();
    mocks.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: { data: url.endsWith("/instructores") ? equipo : [] },
      }),
    );
  });

  it("pide el resumen y muestra la semana, la próxima y las sedes de cada quien", async () => {
    const w = mount(InstructoresView, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: "es",
            messages: {
              es: { ...esMX, tarjetas, listados, profesional },
            },
          }),
        ],
        stubs: { PanelLateral: true, BotonImportar: true },
      },
    });
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/estudio-a/instructores",
      {
        params: { resumen: 1 },
      },
    );
    const [beto, sofia] = w.findAll("li").map((li) => li.text());
    expect(beto).toContain("Condesa · Roma Norte");
    expect(beto).toContain("2 clases · 5 citas");
    expect(beto).toMatch(/dom.*6.*ene.*10:00 · Nivel 1/);
    expect(beto).toContain("4.8 de 5 (12)");
    expect(sofia).toContain("Nada agendado");
    expect(sofia).not.toContain("Reseñas");
  });
});
