import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import AvisosNavegador from "./AvisosNavegador.vue";

/*
| «Avisos en este navegador» (ADR 0225): si el navegador o la plataforma no pueden, no
| se muestra; si puede, se activa y se desactiva aquí.
*/

const mocks = vi.hoisted(() => ({
  estado: vi.fn(),
  activar: vi.fn(),
  desactivar: vi.fn(),
}));
vi.mock("@/lib/pushWeb", () => ({
  estadoPushWeb: mocks.estado,
  activarPushWeb: mocks.activar,
  desactivarPushWeb: mocks.desactivar,
}));
vi.mock("@/lib/api", () => ({ mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));

enableAutoUnmount(afterEach);
beforeEach(() => vi.clearAllMocks());

const montar = () => mount(AvisosNavegador, { global: { plugins: [i18n] } });

describe("avisos en este navegador", () => {
  it("sin soporte o sin llaves de la plataforma no se muestra", async () => {
    mocks.estado.mockResolvedValue("no_disponible");
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="avisos-navegador"]').exists()).toBe(false);
    expect(w.emitted("disponible")?.at(-1)).toEqual([false]);
  });

  it("se activa y se desactiva en este navegador", async () => {
    mocks.estado.mockResolvedValue("inactivo");
    mocks.activar.mockResolvedValue("activo");
    mocks.desactivar.mockResolvedValue("inactivo");
    const w = montar();
    await flushPromises();
    expect(w.emitted("disponible")?.at(-1)).toEqual([true]);

    await w.get('[data-prueba="activar-avisos"]').trigger("click");
    await flushPromises();
    expect(mocks.activar).toHaveBeenCalledWith("/api/v1/app/demo");
    expect(w.find('[data-prueba="avisos-activos"]').exists()).toBe(true);

    await w.get('[data-prueba="desactivar-avisos"]').trigger("click");
    await flushPromises();
    expect(mocks.desactivar).toHaveBeenCalledWith("/api/v1/app/demo");
    expect(w.find('[data-prueba="activar-avisos"]').exists()).toBe(true);
  });

  it("si el navegador los bloqueó, dice cómo permitirlos", async () => {
    mocks.estado.mockResolvedValue("bloqueado");
    const w = montar();
    await flushPromises();
    expect(w.text()).toContain("bloqueados");
  });
});
