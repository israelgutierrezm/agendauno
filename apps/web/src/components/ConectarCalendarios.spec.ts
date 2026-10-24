import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { createMemoryHistory, createRouter } from "vue-router";

import calendariosExternos from "@/i18n/locales/calendariosExternos.es-MX";
import ConectarCalendarios from "./ConectarCalendarios.vue";

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (_e: unknown, porDefecto: string) => porDefecto,
}));
const confirmar = vi.hoisted(() => vi.fn(async () => true));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));

const conexion = {
  id: "c1",
  proveedor: "google",
  cuenta: "coach@gmail.com",
  estado: "activo",
  ultimo_error: null,
  sincronizado_en: "2026-10-01T12:00:00Z",
};

async function montar(ruta = "/mi-perfil") {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: "/mi-perfil", component: { template: "<div />" } }],
  });
  await router.push(ruta);
  await router.isReady();
  const w = mount(ConectarCalendarios, {
    global: {
      plugins: [
        router,
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { calendariosExternos } },
        }),
      ],
    },
  });
  await flushPromises();
  return { w, router };
}

function boton(w: Awaited<ReturnType<typeof montar>>["w"], texto: string) {
  return w.findAll("button").find((b) => b.text() === texto);
}

describe("Google Calendar y Outlook en Mi perfil", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({
      data: {
        data: {
          disponible: true,
          proveedores: [
            { proveedor: "google", configurado: true, conexion: null },
            { proveedor: "microsoft", configurado: false, conexion: null },
          ],
        },
      },
    });
  });

  it("solo ofrece lo que la plataforma configuró y avisa si está disponible", async () => {
    const { w } = await montar();
    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/yo/calendarios");
    expect(w.text()).toContain("Google Calendar");
    expect(w.text()).not.toContain("Outlook");
    expect(w.emitted("disponible")).toEqual([[true]]);
  });

  it("conectar lleva a la autorización", async () => {
    api.post.mockResolvedValue({
      data: { data: { url: "https://accounts.google.com/o/oauth2/v2/auth?x" } },
    });
    const asignar = vi.fn();
    vi.stubGlobal("location", { ...window.location, assign: asignar });
    const { w } = await montar();
    await boton(w, "Conectar")!.trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/yo/calendarios/google/conectar",
    );
    expect(asignar).toHaveBeenCalledWith(
      "https://accounts.google.com/o/oauth2/v2/auth?x",
    );
    vi.unstubAllGlobals();
  });

  it("a la vuelta confirma el código con la sesión y lo quita de la URL", async () => {
    api.post.mockResolvedValue({ data: { data: conexion } });
    const { w, router } = await montar(
      "/mi-perfil?calendario=google&codigo=abc&estado=xyz&otra=1",
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/yo/calendarios/google/confirmar",
      { codigo: "abc", estado: "xyz" },
    );
    expect(router.currentRoute.value.query).toEqual({ otra: "1" });
    expect(toast.exito).toHaveBeenCalledWith(calendariosExternos.listo);
    expect(w.text()).toContain("Conectado · coach@gmail.com");
    expect(boton(w, "Sincronizar ahora")).toBeDefined();
  });

  it("si se canceló en Google, lo dice y no confirma nada", async () => {
    const { router } = await montar("/mi-perfil?calendario=cancelado");
    expect(api.post).not.toHaveBeenCalled();
    expect(toast.error).toHaveBeenCalledWith(calendariosExternos.cancelado);
    expect(router.currentRoute.value.query).toEqual({});
  });

  it("desconectar pregunta antes y quita la conexión", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          disponible: true,
          proveedores: [{ proveedor: "google", configurado: true, conexion }],
        },
      },
    });
    api.delete.mockResolvedValue({});
    const { w } = await montar();
    await boton(w, "Desconectar calendario")!.trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(api.delete).toHaveBeenCalledWith(
      "/api/v1/app/demo/yo/calendarios/google",
    );
    expect(boton(w, "Conectar")).toBeDefined();
  });

  it("dice cómo llegan los cambios y ofrece reconectar si falta el permiso de avisos", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          disponible: true,
          proveedores: [
            {
              proveedor: "google",
              configurado: true,
              conexion: { ...conexion, avisos: "reconectar" },
            },
          ],
        },
      },
    });
    const { w } = await montar();
    expect(w.get('[data-prueba="calendario-avisos"]').text()).toBe(
      calendariosExternos.avisos.reconectar,
    );
    expect(boton(w, calendariosExternos.conectar)).toBeDefined();
  });
});
