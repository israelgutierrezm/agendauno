import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ComunicacionesView from "./ComunicacionesView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(async () => true) }));

const difusion = {
  id: "d1",
  segmento: "todos",
  segmento_etiqueta: "Todos",
  canal: "email",
  asunto: "Promo de octubre",
  total: 40,
  enviada_en: "2026-10-01T12:00:00Z",
  estado: "enviando",
  enviados: 10,
  pendientes: 30,
  descartados: 0,
};

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/comunicaciones/difusiones") ? [difusion] : [],
      },
    }),
  );
});

describe("una campaña en curso se controla (B08)", () => {
  it("dice cuánto falta y se pausa sin salir de la lista", async () => {
    api.post.mockResolvedValue({
      data: { data: { ...difusion, estado: "pausada" } },
    });
    const w = mount(ComunicacionesView, {
      global: {
        plugins: [i18n],
        stubs: { EncabezadoSeccion: true, PanelLateral: true },
      },
    });
    await flushPromises();

    const estado = w.get('[data-prueba="estado-difusion"]');
    expect(estado.text()).toContain("Enviando · faltan 30");
    await estado
      .findAll("button")
      .find((b) => b.text() === "Pausar")!
      .trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/comunicaciones/difusiones/d1/pausar",
      {},
    );
    expect(w.get('[data-prueba="estado-difusion"]').text()).toContain(
      "En pausa",
    );
    expect(
      w
        .get('[data-prueba="estado-difusion"]')
        .findAll("button")
        .some((b) => b.text() === "Reanudar"),
    ).toBe(true);
  });
});
