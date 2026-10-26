import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import bajaNegocio from "@/i18n/locales/bajaNegocio.es-MX";
import DarDeBajaNegocio from "./DarDeBajaNegocio.vue";

/*
| Dar de baja el negocio (ADR 0213): dice en cuántos días se borran los datos; solo se
| confirma escribiendo la dirección del negocio y la contraseña; al quedar, va a la
| pantalla del negocio dado de baja. Si la API lo impide, lo dice en el diálogo.
*/

const mocks = vi.hoisted(() => ({
  darDeBajaNegocio: vi.fn(),
  replace: vi.fn(),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    estudio: { nombre: "Pilates Centro", slug: "pilates-centro" },
    darDeBajaNegocio: mocks.darDeBajaNegocio,
  }),
}));
vi.mock("vue-router", () => ({
  useRouter: () => ({ replace: mocks.replace }),
}));
vi.mock("@/lib/api", () => ({
  mensajeDeError: (e: Error) => e.message,
}));

function montar() {
  return mount(DarDeBajaNegocio, {
    attachTo: document.body,
    props: { dias: 30 },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { bajaNegocio } },
        }),
      ],
    },
  });
}

function escribir(selector: string, valor: string): void {
  const campo = document.querySelector<HTMLInputElement>(selector)!;
  campo.value = valor;
  campo.dispatchEvent(new Event("input"));
}

describe("dar de baja el negocio", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = "";
  });

  it("solo con la dirección y la contraseña da de baja y va a su pantalla", async () => {
    mocks.darDeBajaNegocio.mockResolvedValueOnce(undefined);
    const w = montar();
    expect(w.text()).toContain("Durante 30 días");
    await w.get('[data-prueba="abrir-baja-negocio"]').trigger("click");
    await flushPromises();
    const confirmar = () =>
      document.querySelector<HTMLButtonElement>(
        '[data-prueba="confirmar-baja-negocio"]',
      )!;
    expect(document.body.textContent).toContain("se borran en 30 días");

    escribir("#dbn-contrasena", "secreto123");
    escribir("#dbn-confirmacion", "otro-negocio");
    await flushPromises();
    expect(confirmar().disabled).toBe(true);

    escribir("#dbn-confirmacion", "Pilates-Centro");
    await flushPromises();
    expect(confirmar().disabled).toBe(false);
    document
      .querySelector<HTMLFormElement>('[data-prueba="form-baja-negocio"]')!
      .dispatchEvent(new Event("submit"));
    await flushPromises();
    expect(mocks.darDeBajaNegocio).toHaveBeenCalledWith(
      "secreto123",
      "Pilates-Centro",
      null,
    );
    expect(mocks.replace).toHaveBeenCalledWith({
      name: "negocio-dado-de-baja",
    });
    w.unmount();
  });

  it("si la API lo impide, lo dice en el diálogo y no sale", async () => {
    mocks.darDeBajaNegocio.mockRejectedValueOnce(
      new Error("La contraseña no coincide."),
    );
    const w = montar();
    await w.get('[data-prueba="abrir-baja-negocio"]').trigger("click");
    await flushPromises();
    escribir("#dbn-contrasena", "mala");
    escribir("#dbn-confirmacion", "pilates-centro");
    await flushPromises();
    document
      .querySelector<HTMLFormElement>('[data-prueba="form-baja-negocio"]')!
      .dispatchEvent(new Event("submit"));
    await flushPromises();
    expect(
      document.querySelector('[data-prueba="error-baja-negocio"]')?.textContent,
    ).toContain("La contraseña no coincide.");
    expect(mocks.replace).not.toHaveBeenCalled();
    w.unmount();
  });
});
