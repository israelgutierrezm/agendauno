import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import { resenas } from "@/i18n/locales/gestion.es-MX";
import CalificarClases from "./CalificarClases.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

function pendiente(n: number) {
  return {
    reserva_id: `r${n}`,
    actividad: `Clase ${n}`,
    con: "Ana",
    fecha: "2026-10-03T01:00:00Z",
  };
}
function respuesta(datos: unknown[], meta: Record<string, number>) {
  return {
    data: {
      data: datos,
      meta: { per_page: 5, dias_para_calificar: 14, ...meta },
    },
  };
}
function montar() {
  return mount(CalificarClases, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...es, resenas } },
        }),
      ],
    },
  });
}

describe("califica tus clases", () => {
  beforeEach(() => vi.clearAllMocks());

  it("pagina de cinco en cinco y dice hasta cuántos días atrás se califica", async () => {
    mocks.get.mockResolvedValue(
      respuesta([1, 2, 3, 4, 5].map(pendiente), {
        page: 1,
        ultima_pagina: 2,
        total: 7,
      }),
    );
    const w = montar();
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/resenas/pendientes",
      { params: { page: 1, per_page: 5 } },
    );
    expect(w.findAll("li")).toHaveLength(5);
    expect(w.text()).toContain("los últimos 14 días");
    expect(w.text()).toContain("1–5 de 7");

    mocks.get.mockResolvedValue(
      respuesta([6, 7].map(pendiente), { page: 2, ultima_pagina: 2, total: 7 }),
    );
    await w
      .findAll("nav button")
      .find((b) => b.text() === "2")!
      .trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/mi/resenas/pendientes",
      { params: { page: 2, per_page: 5 } },
    );
    expect(w.findAll("li")).toHaveLength(2);
  });

  it("filtra por fechas desde la primera página y sigue visible aunque no haya en esas fechas", async () => {
    mocks.get.mockResolvedValue(
      respuesta([pendiente(1)], { page: 1, ultima_pagina: 1, total: 1 }),
    );
    const w = montar();
    await flushPromises();

    mocks.get.mockResolvedValue(
      respuesta([], { page: 1, ultima_pagina: 1, total: 0 }),
    );
    await w.get('[data-prueba="calificar-desde"]').setValue("2026-09-20");
    await w.get('[data-prueba="calificar-hasta"]').setValue("2026-09-21");
    await flushPromises();

    expect(mocks.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/mi/resenas/pendientes",
      {
        params: {
          page: 1,
          per_page: 5,
          desde: "2026-09-20",
          hasta: "2026-09-21",
        },
      },
    );
    expect(w.find('[data-prueba="calificar"]').exists()).toBe(true);
    expect(w.text()).toContain("No hay clases por calificar en esas fechas.");

    // Quitar las fechas vuelve a todo.
    await w
      .findAll("button")
      .find((b) => b.text() === "Quitar fechas")!
      .trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/mi/resenas/pendientes",
      { params: { page: 1, per_page: 5 } },
    );
  });

  it("sin nada por calificar no se muestra", async () => {
    mocks.get.mockResolvedValue(
      respuesta([], { page: 1, ultima_pagina: 1, total: 0 }),
    );
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="calificar"]').exists()).toBe(false);
  });
});
