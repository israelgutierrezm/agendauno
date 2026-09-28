import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import operacion from "@/i18n/locales/operacion.es-MX";
import RolesView from "./RolesView.vue";

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(async () => true) }));

const respuesta = {
  data: [
    {
      id: null,
      clave: "propietario",
      nombre: null,
      sistema: true,
      permisos: ["*"],
      personas: 1,
      puede_cambiar: false,
    },
    {
      id: "01ROL",
      clave: "rol_apoyo",
      nombre: "Apoyo",
      sistema: false,
      permisos: ["miembros.ver"],
      personas: 0,
      puede_cambiar: true,
    },
    {
      id: "01GER",
      clave: "rol_gerencia",
      nombre: "Gerencia",
      sistema: false,
      permisos: ["miembros.ver", "pagos.configurar"],
      personas: 2,
      puede_cambiar: false,
    },
  ],
  catalogo: {
    clientes: ["miembros.ver", "miembros.gestionar"],
    negocio: ["pagos.configurar"],
  },
  requisitos: { "miembros.gestionar": ["miembros.ver"] },
  // Quien arma el rol: ve alumnos y administra roles, pero no configura pagos.
  mis_permisos: ["miembros.ver", "miembros.gestionar", "roles.gestionar"],
};

function montar() {
  const i18n = createI18n({
    legacy: false,
    locale: "es-MX",
    messages: { "es-MX": { ...esMX, operacion } },
  });
  return mount(RolesView, {
    global: {
      plugins: [i18n],
      stubs: {
        PanelLateral: {
          props: ["abierto", "titulo"],
          template:
            '<div v-if="abierto" data-prueba="editor"><slot /><slot name="pie" /></div>',
        },
      },
    },
  });
}

describe("roles y permisos", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({ data: respuesta });
    api.post.mockResolvedValue({ data: {} });
  });

  it("separa los roles propios de los del sistema y solo deja cambiar los que caben en lo suyo", async () => {
    const w = montar();
    await flushPromises();

    const propios = w.findAll('[data-prueba="rol-propio"]');
    expect(propios.map((r) => r.find(".rp-nombre").text())).toEqual([
      "Apoyo",
      "Gerencia",
    ]);
    expect(propios[0].text()).toContain("Editar");
    // Gerencia configura pagos: quien arma no lo tiene, no puede cambiarla.
    expect(propios[1].text()).not.toContain("Editar");
    expect(propios[1].text()).toContain("no puedes cambiarlo");
    // Los del sistema no se editan.
    const sistema = w.findAll('[data-prueba="rol-sistema"]');
    expect(sistema[0].find(".rp-nombre").text()).toBe("Dueño");
    expect(sistema[0].text()).toContain("Todos los permisos");
    expect(sistema[0].text()).not.toContain("Editar");
  });

  it("al armar un rol, lo que no tiene aparece deshabilitado y guarda lo elegido", async () => {
    const w = montar();
    await flushPromises();
    await w.find(".tu-btn-primario").trigger("click");

    const editor = w.find('[data-prueba="editor"]');
    await editor.find("#rol-nombre").setValue("Recepción de tarde");
    const opciones = editor.findAll("label.rp-opcion");
    expect(opciones.map((o) => o.text())).toEqual([
      "Ver alumnos y su ficha",
      "Dar de alta y editar alumnos",
      "Configurar pasarelas de pago",
    ]);
    expect(opciones[2].find("input").attributes("disabled")).toBeDefined();

    await opciones[0].find("input").trigger("change");
    await editor.find(".tu-btn-primario").trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith("/api/v1/app/demo/roles", {
      nombre: "Recepción de tarde",
      permisos: ["miembros.ver"],
    });
    expect(toast.exito).toHaveBeenCalled();
  });

  it("un permiso sin lo que necesita no se guarda; se agrega a la vista, no solo", async () => {
    const w = montar();
    await flushPromises();
    await w.find(".tu-btn-primario").trigger("click");
    const editor = w.find('[data-prueba="editor"]');
    await editor.find("#rol-nombre").setValue("Altas");

    // Dar de alta alumnos sin poder verlos: se explica y no deja guardar.
    await editor.findAll("label.rp-opcion")[1].find("input").trigger("change");
    const aviso = editor.find('[data-prueba="necesita-miembros.gestionar"]');
    expect(aviso.text()).toContain("Ver alumnos y su ficha");
    const guardar = () =>
      w.findAll(".tu-btn-primario").at(-1)!.attributes("disabled");
    expect(guardar()).toBeDefined();
    expect(
      (
        editor.findAll("label.rp-opcion")[0].find("input")
          .element as HTMLInputElement
      ).checked,
    ).toBe(false);

    await aviso.find("button").trigger("click");
    expect(
      (
        editor.findAll("label.rp-opcion")[0].find("input")
          .element as HTMLInputElement
      ).checked,
    ).toBe(true);
    expect(guardar()).toBeUndefined();
  });
});
