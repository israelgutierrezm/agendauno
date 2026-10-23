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
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: () => true,
    zonaHoraria: "America/Mexico_City",
  }),
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
    // Los controles van en su propia celda (en el teléfono, a la vista).
    await w
      .get('[data-prueba="acciones-difusion"]')
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
        .get('[data-prueba="acciones-difusion"]')
        .findAll("button")
        .some((b) => b.text() === "Reanudar"),
    ).toBe(true);
  });

  it("si el envío no pasa, el porqué se ve en la revisión y no se cierra mientras envía", async () => {
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/comunicaciones/segmentos")
            ? [
                {
                  clave: "todos",
                  etiqueta: "Todos",
                  descripcion: "",
                  total: 40,
                },
              ]
            : url.endsWith("/comunicaciones/difusiones")
              ? [difusion]
              : [],
        },
      }),
    );
    let rechazar: (e: unknown) => void = () => undefined;
    api.post.mockReturnValue(
      new Promise((_, r) => {
        rechazar = r;
      }),
    );
    const w = mount(ComunicacionesView, {
      global: {
        plugins: [i18n],
        stubs: { EncabezadoSeccion: true, PanelLateral: true, teleport: true },
      },
    });
    await flushPromises();
    // La fecha del historial, en la zona del negocio (12:00 UTC = 06:00 en CDMX).
    expect(w.text()).toContain("06:00");

    await w
      .findAll("button")
      .find((b) => b.text().includes("Todos"))!
      .trigger("click");
    await w.get("#com-asunto").setValue("Promo");
    await w.get("#com-cuerpo").setValue("Hola {{persona_nombre}}");
    await w.get('[data-prueba="revisar-difusion"]').trigger("click");
    await w.get('[data-prueba="enviar-difusion"]').trigger("click");

    // Enviando: el diálogo no se cierra.
    expect(w.get('[role="dialog"]').attributes("aria-busy")).toBe("true");

    rechazar("Ya mandaste esta misma difusión hace un momento.");
    await flushPromises();
    expect(w.get('[data-prueba="error-envio"]').text()).toBe(
      "Ya mandaste esta misma difusión hace un momento.",
    );
  });
});

describe("antes y después de enviar (ADR 0166)", () => {
  it("la revisión dice a cuántos llega por ese canal y quién queda fuera; el historial, cómo salió", async () => {
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/comunicaciones/segmentos")
            ? [
                {
                  clave: "todos",
                  etiqueta: "Todos",
                  descripcion: "",
                  total: 40,
                },
              ]
            : url.endsWith("/comunicaciones/alcance")
              ? {
                  elegibles: 35,
                  sin_consentimiento: 3,
                  sin_email: 2,
                  sin_app: 0,
                }
              : url.endsWith("/comunicaciones/difusiones")
                ? [
                    {
                      ...difusion,
                      pendientes: 0,
                      fallidos: 2,
                      descartados: 3,
                      enviados: 35,
                    },
                  ]
                : [],
        },
      }),
    );
    const w = mount(ComunicacionesView, {
      global: {
        plugins: [i18n],
        stubs: { EncabezadoSeccion: true, PanelLateral: true, teleport: true },
      },
    });
    await flushPromises();
    expect(w.get('[data-prueba="resultado-difusion"]').text()).toBe(
      "35 enviados · 2 no se pudieron enviar · 3 no se mandaron (no desean promociones o se canceló)",
    );

    await w
      .findAll("button")
      .find((b) => b.text().includes("Todos"))!
      .trigger("click");
    await w.get("#com-asunto").setValue("Promo");
    await w.get("#com-cuerpo").setValue("Hola");
    await w.get('[data-prueba="revisar-difusion"]').trigger("click");
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/comunicaciones/alcance",
      { params: { segmento: "todos", canal: "interno" } },
    );
    const dialogo = w.get('[role="dialog"]');
    expect(dialogo.text()).toContain("35");
    expect(w.get('[data-prueba="excluidos-difusion"]').text()).toBe(
      "Quedan fuera: 3 no desean promociones · 2 sin correo.",
    );
  });
});
