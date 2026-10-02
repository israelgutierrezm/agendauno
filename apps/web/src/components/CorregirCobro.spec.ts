import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import CorregirCobro from "./CorregirCobro.vue";

/*
| Corregir un cobro en caja hecho con error (ADR 0086/0087): la forma de pago para
| quien cobra; anularlo (con motivo y confirmación) para quien hace devoluciones.
*/

const mocks = vi.hoisted(() => ({
  post: vi.fn(),
  put: vi.fn(),
  confirmar: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: { post: mocks.post, put: mocks.put },
  fijarBearer: vi.fn(),
  mensajeDeError: () => "Error",
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: mocks.confirmar }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

function montar(permisos: string[], anulable = true) {
  useSesionTenantStore().usuario = { rol: "admin", permisos } as never;
  return mount(CorregirCobro, {
    props: {
      base: "/api/v1/app/demo",
      pago: { id: "p1", metodo: "efectivo", corregible: true, anulable },
    },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
  mocks.post.mockResolvedValue({ data: {} });
  mocks.put.mockResolvedValue({ data: {} });
});

describe("anular un cobro", () => {
  it("pide motivo, confirma y lo anula", async () => {
    mocks.confirmar.mockResolvedValue(true);
    const w = montar(["*"]);
    await w.get('[data-prueba="anular-cobro"]').trigger("click");

    // Sin motivo no se puede.
    expect(w.get('button[type="submit"]').attributes("disabled")).toBeDefined();
    await w.get('[data-prueba="motivo-anular"]').setValue("Aún no paga");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(mocks.confirmar).toHaveBeenCalledWith(
      expect.stringContaining("¿Anular este cobro?"),
      expect.objectContaining({ peligro: true }),
    );
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/pagos/p1/anular",
      { motivo: "Aún no paga" },
    );
    expect(w.emitted("cambiado")).toEqual([["anulado"]]);
  });

  it("si no se confirma, no lo anula", async () => {
    mocks.confirmar.mockResolvedValue(false);
    const w = montar(["*"]);
    await w.get('[data-prueba="anular-cobro"]').trigger("click");
    await w.get('[data-prueba="motivo-anular"]').setValue("Error");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it("sin el permiso de devoluciones, solo se ofrece corregir la forma", () => {
    const w = montar(["ordenes.gestionar"]);
    expect(w.find('[data-prueba="corregir-pago"]').exists()).toBe(true);
    expect(w.find('[data-prueba="anular-cobro"]').exists()).toBe(false);
  });

  it("si el servidor dice que no es anulable, no se ofrece", () => {
    const w = montar(["*"], false);
    expect(w.find('[data-prueba="anular-cobro"]').exists()).toBe(false);
  });
});
