import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ResenasView from "./ResenasView.vue";

/*
| Reseñas con el patrón de los listados: indicadores arriba, filtros por calificación,
| profesional y texto, y el promedio de cada profesional al lado.
*/

const mocks = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, put: mocks.put },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const resena = (id: string, calificacion: number, extra = {}) => ({
  id,
  calificacion,
  comentario: null,
  visible: true,
  actividad: "Corte de cabello",
  con: "Toño",
  persona: `Cliente ${id}`,
  fecha: "2026-09-30T18:00:00Z",
  ...extra,
});

beforeEach(() => {
  mocks.get.mockResolvedValue({
    data: {
      data: [
        resena("a", 5, { comentario: "Excelente corte" }),
        resena("b", 5),
        resena("c", 4, { con: "Memo", comentario: "Buen servicio" }),
        resena("d", 3, { visible: false, comentario: "Tardaron" }),
      ],
      resumen: {
        general: { promedio: 4.25, total: 4 },
        por_profesional: [
          { nombre: "Memo", promedio: 4, total: 1 },
          { nombre: "Toño", promedio: 4.3, total: 3 },
        ],
      },
    },
  });
});

function montar() {
  return mount(ResenasView, { global: { plugins: [i18n] } });
}
const nombres = (w: ReturnType<typeof montar>) =>
  w
    .findAll('[data-prueba="resenas"] > li')
    .map((li) => li.find(".font-medium").text());

describe("reseñas", () => {
  it("solicita la siguiente página y reinicia al buscar", async () => {
    mocks.get.mockResolvedValue({
      data: {
        data: [resena("pagina", 5)],
        meta: { page: 1, ultima_pagina: 3, total: 45, per_page: 20 },
        resumen: {
          general: { promedio: 5, total: 45 },
          por_profesional: [],
          conteos: { todas: 45, "5": 45, "4": 0, "3": 0 },
          con_comentario: 0,
        },
      },
    });
    const w = montar();
    await flushPromises();
    await w.get('button[aria-label="Página 2"]').trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/resenas",
      expect.objectContaining({ params: expect.objectContaining({ page: 2 }) }),
    );
    await w.get('input[type="search"]').setValue("antiguo");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/resenas",
      expect.objectContaining({
        params: expect.objectContaining({ page: 1, q: "antiguo" }),
      }),
    );
  });
  it("arriba, el promedio, el total, las de 5 estrellas y las comentadas", async () => {
    const w = montar();
    await flushPromises();

    const texto = w.text();
    expect(texto).toContain("4.3");
    expect(texto).toContain("50 %");
    // El mejor promedio primero en el lateral.
    expect(w.findAll(".rs-ranking > li")[0]!.text()).toContain("Toño");
  });

  it("filtra por calificación y por texto", async () => {
    const w = montar();
    await flushPromises();
    expect(nombres(w)).toHaveLength(4);

    await w
      .findAll(".tu-segmentado button")
      .find((b) => b.text().startsWith("3 o menos"))!
      .trigger("click");
    expect(nombres(w)).toEqual(["Cliente d"]);

    await w
      .findAll(".tu-segmentado button")
      .find((b) => b.text().startsWith("Todas"))!
      .trigger("click");
    await w.get('input[type="search"]').setValue("servicio");
    expect(nombres(w)).toEqual(["Cliente c"]);
  });
});
