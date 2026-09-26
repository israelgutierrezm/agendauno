import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { bloqueosAgenda } from "@/i18n/locales/gestion.es-MX";
import BloqueosAgenda from "./BloqueosAgenda.vue";

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));

function montar() {
  return mount(BloqueosAgenda, {
    props: {
      base: "/api/v1/app/a",
      proveedorId: "pro1",
      proveedorNombre: "Ana",
      sucursalId: "suc1",
      sucursalNombre: "Roma",
      zona: "America/Mexico_City",
      puedeGestionar: true,
    },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { bloqueosAgenda } },
        }),
      ],
    },
  });
}

function boton(w: ReturnType<typeof montar>, texto: string) {
  return w.findAll("button").find((b) => b.text() === texto);
}

describe("bloqueos de agenda", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: [] } });
  });

  it("sin nada agendado en ese horario, bloquea de una vez", async () => {
    api.post
      .mockResolvedValueOnce({ data: { data: { afectadas: [] } } })
      .mockResolvedValueOnce({ data: { data: {}, afectadas: [] } });
    const w = montar();
    await flushPromises();

    await w.get("#bl-motivo").setValue("Comida");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenNthCalledWith(
      2,
      "/api/v1/app/a/bloqueos",
      expect.objectContaining({
        instructor_id: "pro1",
        motivo: "Comida",
        desde_local: expect.stringMatching(/ 14:00$/),
        hasta_local: expect.stringMatching(/ 15:00$/),
      }),
    );
  });

  it("si ya hay citas ahí, las muestra y pide confirmar", async () => {
    api.post.mockResolvedValueOnce({
      data: {
        data: {
          afectadas: [
            {
              sesion: "s1",
              oferta: "Masaje",
              inicia_en: "2030-01-07T20:30:00Z",
              zona_horaria: "America/Mexico_City",
              reservas: 1,
            },
          ],
        },
      },
    });
    const w = montar();
    await flushPromises();

    await w.get("#bl-motivo").setValue("Vacaciones");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledTimes(1);
    expect(w.text()).toContain("Ya hay 1 citas o clases en ese horario");
    expect(w.text()).toContain("Masaje");
    expect(boton(w, "Bloquear de todos modos")).toBeDefined();

    api.post.mockResolvedValueOnce({ data: { data: {}, afectadas: [] } });
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/a/bloqueos",
      expect.objectContaining({ motivo: "Vacaciones" }),
    );
  });
});
