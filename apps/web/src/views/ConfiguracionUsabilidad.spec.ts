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
        GiroNegocio: {
          template: '<div><input data-prueba="borrador-giro" /></div>',
        },
        TerminologiaNegocio: { template: "<div>Terminología</div>" },
        KioscosNegocio: {
          template: '<div data-prueba="kioscos">Tabletas</div>',
        },
      },
    },
  });
  await flushPromises();
  return { w, router };
}

describe("configuración acotada por opción", () => {
  it("muestra solamente el ajuste elegido y conserva el borrador al cambiar de sección", async () => {
    const { w, router } = await montar("#tipo-negocio");
    expect(w.get("#tipo-negocio").isVisible()).toBe(true);
    expect(w.get("#datos").isVisible()).toBe(false);
    expect(w.get("#terminologia").isVisible()).toBe(false);
    await w.get('[data-prueba="borrador-giro"]').setValue("Sin guardar");
    await router.push({ name: "configuracion", hash: "#terminologia" });
    await flushPromises();
    expect(router.currentRoute.value.hash).toBe("#terminologia");
    expect(w.get("#terminologia").attributes("style") ?? "").not.toContain(
      "display: none",
    );
    // JSDOM conserva el estilo calculado de nodos desconectados; verificamos
    // el v-show aplicado al contenedor sin desmontar su formulario.
    expect(w.get("#tipo-negocio").attributes("style")).toContain(
      "display: none",
    );
    await router.push({ name: "configuracion", hash: "#tipo-negocio" });
    await flushPromises();
    expect(
      (w.get('[data-prueba="borrador-giro"]').element as HTMLInputElement)
        .value,
    ).toBe("Sin guardar");
    // La página pública ya no está aquí: pasó a Captación › Sitio web (ADR 0167).
    expect(w.find("#pagina-publica").exists()).toBe(false);
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

  it("el kiosco de llegada está en Configuración del negocio para quien la administra", async () => {
    const { w } = await montar("#kiosco");
    expect(w.get("h1").text()).toBe("Kiosco de llegada");
    expect(w.find('[data-prueba="kioscos"]').exists()).toBe(true);
    // Solo esa sección: no se monta en las demás (consulta sus tabletas).
    expect(w.get("#datos").isVisible()).toBe(false);
    w.unmount();

    const otra = await montar("#terminologia");
    expect(otra.w.find('[data-prueba="kioscos"]').exists()).toBe(false);
    otra.w.unmount();

    mocks.puede.mockReturnValue(false);
    const sinPermiso = await montar("#kiosco");
    expect(sinPermiso.w.find('[data-prueba="kioscos"]').exists()).toBe(false);
    sinPermiso.w.unmount();
  });
});
