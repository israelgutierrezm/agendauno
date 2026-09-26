import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import { recordarNegocio } from "@/lib/negociosRecientes";
import EntrarView from "./EntrarView.vue";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  replace: vi.fn(),
  route: { query: {} as Record<string, string> },
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get },
  mensajeDeError: () => "No disponible",
}));
vi.mock("@/lib/tenant", () => ({
  slugDeContexto: () => null,
  enSubdominioDeEstudio: () => false,
}));
vi.mock("@/lib/google", () => ({
  clientIdGoogle: () => undefined,
  renderizarBotonGoogle: vi.fn(),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ error: null, cargando: false }),
}));
vi.mock("@/stores/tema", () => ({
  useTemaStore: () => ({ esOscuro: false, alternarModo: vi.fn() }),
}));
vi.mock("vue-router", () => ({
  useRoute: () => mocks.route,
  useRouter: () => ({ replace: mocks.replace, push: vi.fn() }),
  RouterLink: { template: "<a><slot /></a>" },
}));

const montajes: ReturnType<typeof mount>[] = [];
function montar() {
  const wrapper = mount(EntrarView, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
    },
  });
  montajes.push(wrapper);
  return wrapper;
}
function recordarCinco() {
  for (let n = 0; n < 5; n += 1) {
    recordarNegocio({
      slug: `estudio-${n}`,
      nombre: `Estudio ${n}`,
      logo_url: "/logo-prueba.png",
      ciudad: null,
      pais: null,
    });
  }
}

describe("acceso por negocio", () => {
  beforeEach(() => {
    localStorage.clear();
    vi.clearAllMocks();
    mocks.route.query = {};
    mocks.get.mockResolvedValue({ data: { data: [] } });
  });
  afterEach(() => {
    montajes.splice(0).forEach((wrapper) => wrapper.unmount());
  });

  it("no muestra un falso resultado vacío antes de una búsqueda explícita", async () => {
    const wrapper = montar();
    await flushPromises();
    expect(wrapper.text()).toContain("Busca tu negocio");
    expect(wrapper.text()).not.toContain("Busca otro negocio");
    expect(wrapper.find(".tu-selector-vacio").exists()).toBe(false);
    expect(wrapper.find(".tu-login-identidad").exists()).toBe(false);
    await wrapper.get("#buscar-negocio").setValue("Mi academia");
    await wrapper.get(".tu-selector-busqueda").trigger("submit");
    await flushPromises();
    expect(wrapper.find(".tu-selector-vacio").exists()).toBe(true);
  });
  it("conserva el panel visual y muestra todos los negocios recientes", () => {
    recordarCinco();
    const wrapper = montar();
    expect(wrapper.findAll(".tu-negocio-principal")).toHaveLength(5);
    expect(wrapper.findAll(".tu-login-collage > img")).toHaveLength(2);
    expect(wrapper.find(".tu-login-visual").exists()).toBe(true);
    expect(wrapper.find(".tu-login-visual .agendauno-logo").exists()).toBe(
      false,
    );
    expect(wrapper.find(".tu-login-identidad .agendauno-logo").exists()).toBe(
      false,
    );
    expect(mocks.get).not.toHaveBeenCalled();
  });

  it("al seleccionar un negocio reemplaza la marca de AgendaUno por su logo", async () => {
    recordarCinco();
    mocks.get.mockResolvedValue({
      data: { data: { nombre: "Estudio 4", logo_url: "/logo-prueba.png" } },
    });
    const wrapper = montar();
    await wrapper.get(".tu-negocio-principal").trigger("click");
    await flushPromises();
    expect(wrapper.find(".tu-login-identidad .agendauno-logo").exists()).toBe(
      false,
    );
    expect(wrapper.findAll(".tu-login-logo-negocio")).toHaveLength(1);
    expect(wrapper.get(".tu-login-logo-negocio").attributes("alt")).toBe(
      "Estudio 4",
    );
    expect(wrapper.find("#email").exists()).toBe(true);
    expect(wrapper.find(".tu-negocios-recientes").exists()).toBe(false);
    await wrapper.get(".tu-login-cambiar").trigger("click");
    await flushPromises();
    expect(wrapper.findAll(".tu-negocio-principal")).toHaveLength(5);
    expect(wrapper.find("#email").exists()).toBe(false);
  });

  it("un enlace directo abre el formulario con la identidad del negocio", async () => {
    mocks.route.query = { estudio: "pilates" };
    mocks.get.mockResolvedValue({
      data: { data: { nombre: "Pilates Centro", logo_url: "/pilates.png" } },
    });
    const wrapper = montar();
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith("/api/v1/app/pilates/marca");
    expect(wrapper.get(".tu-login-logo-negocio").attributes("src")).toBe(
      "/pilates.png",
    );
    expect(wrapper.find(".tu-login-identidad .agendauno-logo").exists()).toBe(
      false,
    );
    expect(wrapper.find("#email").exists()).toBe(true);
  });

  it("muestra iniciales si el negocio no tiene logo", async () => {
    mocks.route.query = { estudio: "pilates" };
    mocks.get.mockResolvedValue({
      data: { data: { nombre: "Pilates Centro", logo_url: null } },
    });
    const wrapper = montar();
    await flushPromises();
    expect(wrapper.get(".tu-login-iniciales").text()).toBe("PC");
    expect(wrapper.find(".tu-login-identidad .agendauno-logo").exists()).toBe(
      false,
    );
  });
});
