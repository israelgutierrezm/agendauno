import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import { i18n } from "@/i18n";
import ConfiguracionView from "./ConfiguracionView.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn(), puede: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    estudio: { publicado: true },
    puede: mocks.puede,
    cargarYo: async () => {},
  }),
}));

beforeEach(() => {
  vi.clearAllMocks();
  mocks.puede.mockReturnValue(true);
  mocks.get.mockResolvedValue({ data: { data: { logo_url: null } } });
});
async function montar(hash: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        name: "configuracion",
        path: "/configuracion",
        component: { template: "<div />" },
      },
    ],
  });
  await router.push({ name: "configuracion", hash });
  const w = mount(ConfiguracionView, {
    global: {
      plugins: [router, i18n],
      stubs: {
        CargadorLogo: { template: "<div>Logo</div>" },
        PerfilPublicoNegocio: {
          template: '<div><input data-prueba="borrador-perfil" /></div>',
        },
        GiroNegocio: { template: "<div>Giro</div>" },
        TerminologiaNegocio: { template: "<div>Terminología</div>" },
      },
    },
  });
  await flushPromises();
  return { w, router };
}

describe("configuración acotada por opción", () => {
  it("muestra solamente el ajuste elegido y conserva el borrador al cambiar de sección", async () => {
    const { w, router } = await montar("#pagina-publica");
    expect(w.get("#pagina-publica").isVisible()).toBe(true);
    expect(w.get("#datos").isVisible()).toBe(false);
    expect(w.get("#tipo-negocio").isVisible()).toBe(false);
    await w.get('[data-prueba="borrador-perfil"]').setValue("Sin guardar");
    await router.push({ name: "configuracion", hash: "#terminologia" });
    await flushPromises();
    expect(router.currentRoute.value.hash).toBe("#terminologia");
    expect(w.get("#terminologia").isVisible()).toBe(true);
    // JSDOM conserva el estilo calculado de nodos desconectados; verificamos
    // el v-show aplicado al contenedor sin desmontar su formulario.
    expect(w.get("#pagina-publica").attributes("style")).toContain(
      "display: none",
    );
    await router.push({ name: "configuracion", hash: "#pagina-publica" });
    await flushPromises();
    expect(
      (w.get('[data-prueba="borrador-perfil"]').element as HTMLInputElement)
        .value,
    ).toBe("Sin guardar");
    w.unmount();
  });
  it("mantiene la vista general sin ancla y no expone editores sin permiso", async () => {
    const { w } = await montar("");
    expect(w.get("#datos").isVisible()).toBe(true);
    expect(w.get("#terminologia").isVisible()).toBe(true);
    w.unmount();
    mocks.puede.mockReturnValue(false);
    const lectura = await montar("#terminologia");
    expect(lectura.w.find("#terminologia").exists()).toBe(false);
    expect(lectura.w.find("#pagina-publica").exists()).toBe(false);
    lectura.w.unmount();
  });
});
