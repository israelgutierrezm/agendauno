import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import eliminarCuenta from "@/i18n/locales/eliminarCuenta.es-MX";
import EliminarMiCuenta from "./EliminarMiCuenta.vue";

/*
| Eliminar la cuenta propia (ADR 0211): se confirma con la contraseña; al quedar
| eliminada va a Entrar; si la API lo impide (el único dueño), lo dice en el diálogo.
*/

const mocks = vi.hoisted(() => ({
  eliminarCuenta: vi.fn(),
  replace: vi.fn(),
  exito: vi.fn(),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    estudio: { nombre: "Pilates Centro" },
    eliminarCuenta: mocks.eliminarCuenta,
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: mocks.exito, error: vi.fn() }),
}));
vi.mock("vue-router", () => ({
  useRouter: () => ({ replace: mocks.replace }),
}));
vi.mock("@/lib/api", () => ({
  mensajeDeError: (e: Error) => e.message,
}));

function montar() {
  return mount(EliminarMiCuenta, {
    attachTo: document.body,
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { eliminarCuenta } },
        }),
      ],
    },
  });
}

describe("eliminar mi cuenta", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = "";
  });

  it("confirma con la contraseña, elimina la cuenta y va a Entrar", async () => {
    mocks.eliminarCuenta.mockResolvedValueOnce("Tu cuenta quedó eliminada.");
    const w = montar();
    await w.get('[data-prueba="abrir-eliminar-cuenta"]').trigger("click");
    await flushPromises();
    const confirmar = document.querySelector<HTMLButtonElement>(
      '[data-prueba="confirmar-eliminar-cuenta"]',
    )!;
    expect(confirmar.disabled).toBe(true);
    const campo = document.querySelector<HTMLInputElement>("#emc-contrasena")!;
    campo.value = "secreto123";
    campo.dispatchEvent(new Event("input"));
    await flushPromises();
    document
      .querySelector<HTMLFormElement>('[data-prueba="eliminar-cuenta"]')!
      .dispatchEvent(new Event("submit"));
    await flushPromises();
    expect(mocks.eliminarCuenta).toHaveBeenCalledWith("secreto123", null);
    expect(mocks.exito).toHaveBeenCalledWith("Tu cuenta quedó eliminada.");
    expect(mocks.replace).toHaveBeenCalledWith({ name: "entrar" });
    w.unmount();
  });

  it("si la API lo impide, lo dice en el diálogo y no sale", async () => {
    mocks.eliminarCuenta.mockRejectedValueOnce(
      new Error("Eres el dueño del negocio: primero da de baja el negocio."),
    );
    const w = montar();
    await w.get('[data-prueba="abrir-eliminar-cuenta"]').trigger("click");
    await flushPromises();
    const campo = document.querySelector<HTMLInputElement>("#emc-contrasena")!;
    campo.value = "secreto123";
    campo.dispatchEvent(new Event("input"));
    await flushPromises();
    document
      .querySelector<HTMLFormElement>('[data-prueba="eliminar-cuenta"]')!
      .dispatchEvent(new Event("submit"));
    await flushPromises();
    expect(
      document.querySelector('[data-prueba="error-eliminar-cuenta"]')
        ?.textContent,
    ).toContain("primero da de baja el negocio");
    expect(mocks.replace).not.toHaveBeenCalled();
    w.unmount();
  });
});
