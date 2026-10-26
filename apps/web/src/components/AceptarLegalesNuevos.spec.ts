import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { reactive } from "vue";

import legalesNuevos from "@/i18n/locales/legalesNuevos.es-MX";
import AceptarLegalesNuevos from "./AceptarLegalesNuevos.vue";

/*
| Versión nueva de los legales (ADR 0216): el dueño la ve al entrar, la acepta después
| de marcar que la leyó; nadie más la ve.
*/

const mocks = vi.hoisted(() => ({ aceptarLegales: vi.fn(), exito: vi.fn() }));
const sesion = reactive({
  usuario: { rol: "propietario" } as { rol: string },
  estudio: {
    legales_pendientes: [
      { tipo: "terminos", version: 2, vigente_desde: "2026-10-01" },
    ],
  },
  aceptarLegales: mocks.aceptarLegales,
});
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: mocks.exito, error: vi.fn() }),
}));
vi.mock("@/lib/api", () => ({ mensajeDeError: (e: Error) => e.message }));

function montar() {
  return mount(AceptarLegalesNuevos, {
    attachTo: document.body,
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { legalesNuevos } },
        }),
      ],
    },
  });
}

describe("aceptar legales nuevos", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = "";
    sesion.usuario = { rol: "propietario" };
  });

  it("el dueño los acepta después de marcar que los leyó", async () => {
    mocks.aceptarLegales.mockResolvedValueOnce(undefined);
    const w = montar();
    await flushPromises();
    expect(document.body.textContent).toContain("Términos y condiciones");
    const boton = document.querySelector<HTMLButtonElement>(
      '[data-prueba="aceptar-legales"]',
    )!;
    expect(boton.disabled).toBe(true);
    const casilla = document.querySelector<HTMLInputElement>(
      '[data-prueba="leido-legales"]',
    )!;
    casilla.checked = true;
    casilla.dispatchEvent(new Event("change"));
    await flushPromises();
    boton.click();
    await flushPromises();
    expect(mocks.aceptarLegales).toHaveBeenCalled();
    expect(mocks.exito).toHaveBeenCalled();
    w.unmount();
  });

  it("a quien no es el dueño no se le pide", async () => {
    sesion.usuario = { rol: "recepcionista" };
    const w = montar();
    await flushPromises();
    expect(document.querySelector('[data-prueba="legales-nuevos"]')).toBeNull();
    w.unmount();
  });
});
