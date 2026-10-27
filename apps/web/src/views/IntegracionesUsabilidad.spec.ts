import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { i18n } from "@/i18n";
import IntegracionesView from "./IntegracionesView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "No se pudo guardar",
  erroresPorCampo: () => ({}),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    esCitas: true,
    estudio: {},
    puede: () => true,
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

async function montar() {
  const w = mount(IntegracionesView, {
    global: { plugins: [i18n], stubs: { Teleport: true } },
  });
  await flushPromises();
  return w;
}
beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((ruta: string) =>
    Promise.resolve({
      data: {
        data: [],
        ...(ruta.endsWith("llaves-api")
          ? { scopes: ["agenda.ver"] }
          : { eventos_disponibles: ["reserva.confirmada"] }),
      },
    }),
  );
});

describe("integraciones: llaves de API", () => {
  it("dice qué dirección del negocio consultar con cada permiso", async () => {
    api.get.mockImplementation((ruta: string) =>
      Promise.resolve({
        data: {
          data: [],
          ...(ruta.endsWith("llaves-api")
            ? {
                scopes: [
                  "miembros.ver",
                  "agenda.ver",
                  "reservas.ver",
                  "derechos.ver",
                  "ordenes.ver",
                ],
              }
            : { eventos_disponibles: [] }),
        },
      }),
    );
    const w = await montar();
    const direcciones = w.get('[data-prueba="direcciones-api"]');
    const base = `${window.location.origin}/api/v1/app/demo/integracion`;
    expect(direcciones.findAll("code").map((c) => c.text())).toEqual([
      `GET ${base}/miembros`,
      `GET ${base}/sesiones`,
      `GET ${base}/reservas`,
      `GET ${base}/derechos`,
      `GET ${base}/ordenes`,
    ]);
    expect(direcciones.text()).toContain("X-API-Key");
    expect(direcciones.get('[data-alcance="agenda.ver"]').text()).toContain(
      "Agenda",
    );
    w.unmount();
  });

  it("el kiosco de llegada ya no es una pestaña de Integraciones", async () => {
    const w = await montar();
    expect(w.text()).not.toContain("Kiosco de llegada");
    w.unmount();
  });
});

describe("integraciones: formularios a demanda", () => {
  it("no muestra el formulario hasta solicitar una llave y cancelar no crea accesos", async () => {
    const w = await montar();
    expect(w.find("#ll-nombre").exists()).toBe(false);
    await w.get('[data-prueba="nueva-llave"]').trigger("click");
    expect(w.get('[role="dialog"]').text()).toContain("Elige un nombre");
    expect(w.find("#ll-nombre").exists()).toBe(true);
    await w
      .findAll("button")
      .find((b) => b.text() === "Cancelar")!
      .trigger("click");
    expect(w.find("#ll-nombre").exists()).toBe(false);
    expect(api.post).not.toHaveBeenCalled();
    w.unmount();
  });

  it("conserva la petición y muestra el secreto una sola vez fuera del editor", async () => {
    api.post.mockResolvedValue({
      data: { data: { secreto: "secreto-de-prueba" } },
    });
    const w = await montar();
    await w.get('[data-prueba="nueva-llave"]').trigger("click");
    await w.get("#ll-nombre").setValue("Mi conexión");
    await w.get('#integracion-llave input[type="checkbox"]').setValue(true);
    await w.get("#integracion-llave").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/demo/llaves-api", {
      nombre: "Mi conexión",
      scopes: ["agenda.ver"],
    });
    expect(w.find('[role="dialog"]').exists()).toBe(false);
    expect(w.text()).toContain("secreto-de-prueba");
    w.unmount();
  });

  it("el webhook conserva su URL y selección de eventos en caso de error", async () => {
    api.post.mockRejectedValue(new Error("error"));
    const w = await montar();
    await w
      .findAll("button")
      .find((b) => b.text() === "Webhooks")!
      .trigger("click");
    expect(w.find("#wh-url").exists()).toBe(false);
    await w.get('[data-prueba="nuevo-webhook"]').trigger("click");
    await w.get("#wh-url").setValue("https://example.test/avisos");
    await w.get("#integracion-webhook").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/webhooks-salientes",
      { url: "https://example.test/avisos", eventos: null },
    );
    expect(w.find('[role="dialog"]').exists()).toBe(true);
    expect((w.get("#wh-url").element as HTMLInputElement).value).toBe(
      "https://example.test/avisos",
    );
    w.unmount();
  });
});
