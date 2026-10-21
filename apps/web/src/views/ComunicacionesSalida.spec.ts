import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ComunicacionesView from "./ComunicacionesView.vue";

/*
| La bandeja de salida va por páginas: el API busca, filtra por estado, canal y fechas y
| cuenta cuántos hay de cada estado.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: () => true,
    // WhatsApp activo en el negocio: se puede filtrar por él.
    estudio: { whatsapp_clientes: true },
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(async () => true) }));

const mensaje = {
  id: "m1",
  persona: "Valeria",
  canal: "email",
  destinatario: "vale@correo.mx",
  asunto: "Tu plan vence pronto",
  estado: "fallido",
  intentos: 3,
  creado_en: "2026-10-01T12:00:00Z",
  enviado_en: null,
  ultimo_error: "Buzón lleno",
};

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/mensajes")
        ? {
            data: [mensaje],
            meta: {
              total: 30,
              page: 1,
              per_page: 25,
              ultima_pagina: 2,
              conteos: { encolado: 0, enviado: 28, fallido: 2, descartado: 0 },
            },
          }
        : { data: [] },
    }),
  );
});

async function abrirSalida() {
  const w = mount(ComunicacionesView, {
    global: {
      plugins: [i18n],
      stubs: { EncabezadoSeccion: true, PanelLateral: true },
    },
  });
  await flushPromises();
  await w
    .findAll("button")
    .find((b) => b.text() === "Bandeja de salida")!
    .trigger("click");
  await flushPromises();
  return w;
}

describe("bandeja de salida por páginas", () => {
  it("cuenta por estado, filtra en el API y pagina", async () => {
    const w = await abrirSalida();

    expect(w.get('[data-prueba="salida-estado-fallido"]').text()).toBe(
      "Falló (2)",
    );
    expect(w.text()).toContain("Buzón lleno");
    expect(w.text()).toContain("1–25 de 30");

    await w.get('[data-prueba="salida-estado-fallido"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/mensajes", {
      params: { page: 1, estado: "fallido" },
    });

    await w.get('[data-prueba="salida-canal"]').setValue("whatsapp");
    await w.get('[data-prueba="salida-desde"]').setValue("2026-10-01");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/mensajes", {
      params: {
        page: 1,
        estado: "fallido",
        canal: "whatsapp",
        desde: "2026-10-01",
      },
    });
  });
});
