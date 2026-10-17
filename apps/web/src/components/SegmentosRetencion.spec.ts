import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import SegmentosRetencion from "./SegmentosRetencion.vue";

/*
| Retención por segmentos: cada grupo con cuántos son, quiénes y por qué, y qué hacer
| con todos (escribirles o crear su seguimiento). Un error no parece «nadie».
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const confirmar = vi.hoisted(() => vi.fn(async () => true));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
vi.mock("@/lib/acceso", () => ({ puedeEntrar: () => true }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));

const segmentos = [
  {
    clave: "sin_asistir",
    etiqueta: "Sin asistir",
    total: 2,
    con_promociones: 1,
  },
  {
    clave: "pago_fallido",
    etiqueta: "Su pago falló",
    total: 0,
    con_promociones: 0,
  },
];

function montar() {
  return mount(SegmentosRetencion, {
    global: {
      plugins: [i18n],
      stubs: {
        RouterLink: {
          props: ["to"],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/retencion/segmentos")
        ? { data: segmentos }
        : {
            data: [
              {
                id: "p1",
                nombre: "Ana Ruiz",
                detalle: "Su última clase fue hace 26 días.",
                recibe_promociones: false,
                seguimiento_abierto: true,
              },
            ],
            meta: { total: 1, page: 1, per_page: 25, ultima_pagina: 1 },
          },
    }),
  );
});

describe("para recuperar", () => {
  it("muestra cada grupo con su porqué y abre quiénes son", async () => {
    const w = montar();
    await flushPromises();
    const grupo = w.get('[data-prueba="segmento-sin_asistir"]');
    expect(grupo.text()).toContain("2 personas");
    expect(
      grupo.get('[data-prueba="escribir-sin_asistir"]').attributes("data-to"),
    ).toBe(
      JSON.stringify({
        name: "comunicaciones",
        query: { segmento: "sin_asistir" },
      }),
    );
    // Un grupo vacío no ofrece acciones.
    expect(w.find('[data-prueba="seguimiento-pago_fallido"]').exists()).toBe(
      false,
    );

    await grupo.findAll("button")[0]!.trigger("click");
    await flushPromises();
    expect(grupo.text()).toContain("Ana Ruiz");
    expect(grupo.text()).toContain("Su última clase fue hace 26 días.");
    expect(grupo.text()).toContain("Ya tiene seguimiento");
    expect(grupo.text()).toContain("No desea promociones");
  });

  it("crea el seguimiento de todo el grupo y dice cuántas tareas se crearon", async () => {
    api.post.mockResolvedValue({ data: { data: { creadas: 2, omitidas: 0 } } });
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="seguimiento-sin_asistir"]').trigger("click");
    await flushPromises();

    expect(confirmar.mock.calls[0]?.[0]).toContain("2 personas");
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/retencion/segmentos/sin_asistir/seguimientos",
    );
    expect(toast.exito).toHaveBeenCalledWith(
      "Se crearon 2 tareas de seguimiento.",
    );
  });

  it("si no se cargan los grupos lo dice y deja reintentar (no es «nadie»)", async () => {
    api.get.mockRejectedValueOnce(new Error("red"));
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="error-segmentos"]').exists()).toBe(true);
    expect(w.text()).not.toContain("Nadie por recuperar");

    await w.get('[data-prueba="error-segmentos"] button').trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="segmento-sin_asistir"]').exists()).toBe(true);
  });
});
