import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import PanelRoles from "@/components/PanelRoles.vue";
import { recordarNegocio } from "@/lib/negociosRecientes";
import EntrarView from "./EntrarView.vue";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  replace: vi.fn(),
  push: vi.fn(),
  route: { query: {} as Record<string, string> },
  sesion: {
    error: null,
    cargando: false,
    requiereElegirRol: false,
    destinoAlEntrar: "panel",
    iniciarSesion: vi.fn(),
  },
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
  useSesionTenantStore: () => mocks.sesion,
}));
vi.mock("@/stores/tema", () => ({
  useTemaStore: () => ({ esOscuro: false, alternarModo: vi.fn() }),
}));
vi.mock("vue-router", () => ({
  useRoute: () => mocks.route,
  useRouter: () => ({ replace: mocks.replace, push: mocks.push }),
  RouterLink: { template: "<a><slot /></a>" },
}));

const montajes: ReturnType<typeof mount>[] = [];
function montar() {
  const wrapper = mount(EntrarView, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
      // El panel de roles al entrar tiene su propia prueba (ElegirRol.spec).
      stubs: { PanelRoles: true },
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
    mocks.sesion.requiereElegirRol = false;
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
    expect(wrapper.get(".tu-login-identidad img").attributes("src")).toBe(
      "/assets/brand/agendauno/final-v2/isotipo.png",
    );
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
      true,
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
    expect(wrapper.get(".tu-login-identidad img").attributes("src")).toContain(
      "isotipo.png",
    );
  });

  it("con varios roles, al entrar abre el panel lateral para elegir con cuál (sin navegar)", async () => {
    mocks.route.query = { estudio: "pilates" };
    mocks.get.mockResolvedValue({
      data: { data: { nombre: "Pilates Centro", logo_url: null } },
    });
    mocks.sesion.iniciarSesion.mockImplementation(async () => {
      mocks.sesion.requiereElegirRol = true;
    });
    const wrapper = montar();
    await flushPromises();
    await wrapper.get("#email").setValue("ana@correo.test");
    await wrapper.get("#password").setValue("prueba");
    await wrapper.get("form.tu-login-form").trigger("submit");
    await flushPromises();

    expect(mocks.sesion.iniciarSesion).toHaveBeenCalled();
    expect(mocks.push).not.toHaveBeenCalled();
    const panel = wrapper.findComponent(PanelRoles);
    expect(panel.props("abierto")).toBe(true);
    expect(panel.props("alEntrar")).toBe(true);
  });

  it("con un solo rol, al entrar va directo a su inicio", async () => {
    mocks.route.query = { estudio: "pilates" };
    mocks.get.mockResolvedValue({
      data: { data: { nombre: "Pilates Centro", logo_url: null } },
    });
    mocks.sesion.iniciarSesion.mockResolvedValue(undefined);
    const wrapper = montar();
    await flushPromises();
    await wrapper.get("#email").setValue("ana@correo.test");
    await wrapper.get("#password").setValue("prueba");
    await wrapper.get("form.tu-login-form").trigger("submit");
    await flushPromises();

    expect(mocks.push).toHaveBeenCalledWith({ name: "panel" });
    expect(wrapper.findComponent(PanelRoles).props("abierto")).toBe(false);
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

  it("muestra el isotipo si el negocio no tiene logo, conservando su nombre", async () => {
    mocks.route.query = { estudio: "pilates" };
    mocks.get.mockResolvedValue({
      data: { data: { nombre: "Pilates Centro", logo_url: null } },
    });
    const wrapper = montar();
    await flushPromises();
    expect(wrapper.get("h1").text()).toContain("Pilates Centro");
    expect(wrapper.get(".tu-login-identidad img").attributes("src")).toContain(
      "isotipo.png",
    );
    expect(wrapper.find(".tu-login-identidad .agendauno-logo").exists()).toBe(
      true,
    );
  });

  it("usa el isotipo si falla el logo y permite cargar el de otro negocio", async () => {
    recordarCinco();
    mocks.get.mockResolvedValue({
      data: { data: { nombre: "Estudio 4", logo_url: "/logo-prueba.png" } },
    });
    const wrapper = montar();
    await wrapper.get(".tu-negocio-principal").trigger("click");
    await flushPromises();
    await wrapper.get(".tu-login-logo-negocio").trigger("error");
    expect(wrapper.find(".tu-login-logo-negocio").exists()).toBe(false);
    expect(wrapper.get(".tu-login-identidad img").attributes("src")).toContain(
      "isotipo.png",
    );
    expect(wrapper.get("h1").text()).toContain("Estudio 4");
    await wrapper.get(".tu-login-cambiar").trigger("click");
    await flushPromises();
    mocks.get.mockResolvedValue({
      data: { data: { nombre: "Estudio 3", logo_url: "/otro-logo.png" } },
    });
    await wrapper.findAll(".tu-negocio-principal")[1]!.trigger("click");
    await flushPromises();
    expect(wrapper.get(".tu-login-logo-negocio").attributes("src")).toBe(
      "/otro-logo.png",
    );
    expect(wrapper.find(".tu-login-identidad .agendauno-logo").exists()).toBe(
      false,
    );
  });
});
