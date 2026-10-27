import { flushPromises, shallowMount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { createMemoryHistory, createRouter } from "vue-router";
import { ref } from "vue";

import esMX from "@/i18n/locales/es-MX";
import App from "./App.vue";

/*
| El menú lateral del teléfono (cajón): cerrado es `inert` (ni Tab ni un lector de
| pantalla llegan a sus enlaces escondidos fuera de la pantalla); abierto, el foco
| entra en él y, al cerrarse, vuelve al botón del menú. En escritorio el lateral
| siempre se ve y nunca es `inert`.
*/

vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "a",
    bearer: "t",
    autenticado: true,
    validando: false,
    requiereElegirRol: false,
    tieneVariosRoles: false,
    rutaInicio: "inicio",
    estudio: { nombre: "Estudio Uno", modulos: [] },
    usuario: null,
    cerrarSesion: vi.fn(),
  }),
}));
vi.mock("@/stores/apariencia", () => ({
  useAparienciaStore: () => ({ restaurar: vi.fn(), pausar: vi.fn() }),
}));
vi.mock("@/stores/tema", () => ({
  useTemaStore: () => ({
    inicializar: vi.fn(),
    alternarModo: vi.fn(),
    esOscuro: false,
  }),
}));
vi.mock("@/lib/ubicacionActual", () => ({
  useUbicacionActual: () => ({
    titulo: ref("Agenda"),
    icono: ref(""),
    migas: ref([]),
  }),
}));
vi.mock("@/lib/sucursalOperativa", () => ({
  useSucursales: () => ({ varias: ref(false), lista: ref([]) }),
}));
vi.mock("@/lib/participantes", () => ({
  useParticipantes: () => ({ actual: null, reiniciar: vi.fn() }),
}));
vi.mock("@/lib/miCuenta", () => ({
  identidadDeSesion: () => "a:t",
  reiniciarMiCuenta: vi.fn(),
}));

function conAncho(escritorio: boolean): void {
  window.matchMedia = vi.fn().mockImplementation((consulta: string) => ({
    matches: consulta.includes("min-width") ? escritorio : false,
    media: consulta,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })) as unknown as typeof window.matchMedia;
}

async function montar() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: "/",
        name: "inicio",
        component: { template: "<div />" },
        meta: { requiereSesion: true },
      },
    ],
  });
  await router.push("/");
  await router.isReady();
  const w = shallowMount(App, {
    attachTo: document.body,
    global: {
      plugins: [
        router,
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: esMX },
        }),
      ],
    },
  });
  await flushPromises();
  return w;
}

describe("menú lateral en el teléfono", () => {
  afterEach(() => {
    document.body.innerHTML = "";
    document.body.style.overflow = "";
  });

  it("cerrado es inert; al abrirse se quita y el foco entra; al cerrarse vuelve al botón", async () => {
    conAncho(false);
    const w = await montar();
    const menu = w.get('[data-prueba="menu-lateral"]');
    const boton = w.get('[data-prueba="boton-menu"]');

    expect(menu.attributes("inert")).toBeDefined();
    expect(boton.attributes("aria-expanded")).toBe("false");

    (boton.element as HTMLElement).focus();
    await boton.trigger("click");
    await flushPromises();
    expect(menu.attributes("inert")).toBeUndefined();
    expect(boton.attributes("aria-expanded")).toBe("true");
    expect(document.activeElement).toBe(menu.element);

    window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    await flushPromises();
    expect(menu.attributes("inert")).toBeDefined();
    expect(document.activeElement).toBe(boton.element);
    w.unmount();
  });

  it("tocar el velo lo cierra y vuelve a quedar inert", async () => {
    conAncho(false);
    const w = await montar();
    await w.get('[data-prueba="boton-menu"]').trigger("click");
    await flushPromises();
    expect(
      w.get('[data-prueba="menu-lateral"]').attributes("inert"),
    ).toBeUndefined();

    await w.get(".bg-black\\/50").trigger("click");
    await flushPromises();
    expect(
      w.get('[data-prueba="menu-lateral"]').attributes("inert"),
    ).toBeDefined();
    w.unmount();
  });

  it("en escritorio el lateral nunca es inert", async () => {
    conAncho(true);
    const w = await montar();
    expect(
      w.get('[data-prueba="menu-lateral"]').attributes("inert"),
    ).toBeUndefined();
    w.unmount();
  });
});
