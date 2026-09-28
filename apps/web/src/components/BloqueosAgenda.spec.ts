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

describe("bloqueos de una sala", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/recursos")
            ? [
                {
                  id: "r1",
                  nombre: "Cabina 1",
                  sucursal_id: "suc1",
                  activo: true,
                },
                {
                  id: "r2",
                  nombre: "Otra sede",
                  sucursal_id: "suc2",
                  activo: true,
                },
              ]
            : [
                {
                  id: "b1",
                  ambito: "sala",
                  instructor_id: null,
                  sucursal_id: null,
                  recurso_id: "r1",
                  desde: "2099-01-07T15:00:00Z",
                  hasta: "2099-01-07T17:00:00Z",
                  todo_el_dia: false,
                  zona_horaria: "America/Mexico_City",
                  motivo: "Mantenimiento",
                  creado_por: null,
                },
              ],
        },
      }),
    );
  });

  it("muestra los de las salas de la sede y bloquea una sala", async () => {
    api.post
      .mockResolvedValueOnce({ data: { data: { afectadas: [] } } })
      .mockResolvedValueOnce({ data: { data: {} } });
    const w = montar();
    await flushPromises();
    expect(w.text()).toContain("Mantenimiento");
    expect(w.text()).toContain("Cabina 1");

    await boton(w, "Una sala")!.trigger("click");
    // Solo las salas de esta sede.
    expect(w.findAll("#bl-sala option").map((o) => o.text())).toEqual([
      "Cabina 1",
    ]);
    await w.get("#bl-motivo").setValue("Pintura");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenNthCalledWith(
      2,
      "/api/v1/app/a/bloqueos",
      expect.objectContaining({ recurso_id: "r1", motivo: "Pintura" }),
    );
    expect(api.post.mock.calls[1][1]).not.toHaveProperty("instructor_id");
  });
});
