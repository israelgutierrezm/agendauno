import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { reactive } from "vue";

import esMX from "@/i18n/locales/es-MX";
import { miPerfil } from "@/i18n/locales/equipo.es-MX";
import { miPrivacidad } from "@/i18n/locales/gestion.es-MX";
import MiPerfilView from "./MiPerfilView.vue";

const estado = vi.hoisted(() => ({
  usuario: {
    rol: "miembro",
    nombre: "Ana Demo",
    nombre_pila: "Ana",
    primer_apellido: "Demo",
    email: "ana@example.test",
    foto_url: null,
    roles_disponibles: [{ clave: "miembro", faceta: "miembro" }],
  },
}));
const sesion = reactive({
  slug: "demo",
  usuario: estado.usuario,
  actualizarUsuario: vi.fn(),
});
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
const api = vi.hoisted(() => ({
  get: vi.fn(),
  put: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/lib/google", () => ({
  clientIdGoogle: () => undefined,
  renderizarBotonGoogle: vi.fn(),
}));

function montar() {
  return mount(MiPerfilView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...esMX, miPerfil, miPrivacidad } },
          missingWarn: false,
          fallbackWarn: false,
        }),
      ],
      stubs: {
        PanelApariencia: true,
        MiPrivacidad: {
          props: ["integrado"],
          template: '<div data-prueba="privacidad" />',
        },
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  sesion.usuario.rol = "miembro";
  sesion.usuario.roles_disponibles = [{ clave: "miembro", faceta: "miembro" }];
  Object.assign(sesion.usuario, { tiene_ficha: false, celular: null });
});

describe("perfil unificado", () => {
  it("el miembro tiene su perfil y privacidad juntos", () => {
    const w = montar();
    expect(w.find('[data-prueba="privacidad"]').exists()).toBe(true);
    expect(w.findAll("h1")).toHaveLength(1);
    expect(w.get('input[autocomplete="given-name"]').element).toHaveProperty(
      "value",
      "Ana",
    );
    w.unmount();
  });

  it.each(["propietario", "instructor"])(
    "no ofrece privacidad de alumno al rol %s",
    (rol) => {
      sesion.usuario.rol = rol;
      const w = montar();
      expect(w.find('[data-prueba="privacidad"]').exists()).toBe(false);
      expect(w.find('input[autocomplete="given-name"]').exists()).toBe(true);
      w.unmount();
    },
  );

  it("se ordena en Datos personales, Acceso y Preferencias y privacidad", () => {
    const w = montar();
    expect(w.findAll("h2.mp-seccion").map((h) => h.text())).toEqual([
      "Datos personales",
      "Acceso",
      "Preferencias y privacidad",
    ]);
    w.unmount();
  });

  it("con ficha de cliente edita su celular junto a su nombre", async () => {
    Object.assign(sesion.usuario, { tiene_ficha: true, celular: "5511112222" });
    api.put.mockResolvedValue({ data: { data: { usuario: sesion.usuario } } });
    const w = montar();
    const celular = w.get('[data-prueba="celular"]');
    expect(celular.element).toHaveProperty("value", "5511112222");

    await celular.setValue("55 3333 4444");
    await w.findAll("form")[0].trigger("submit");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/yo/perfil",
      expect.objectContaining({ nombre: "Ana", celular: "55 3333 4444" }),
    );
    w.unmount();
  });

  it("sin ficha (personal) no hay celular que editar ni se manda", async () => {
    sesion.usuario.rol = "propietario";
    api.put.mockResolvedValue({ data: { data: { usuario: sesion.usuario } } });
    const w = montar();
    expect(w.find('[data-prueba="celular"]').exists()).toBe(false);

    await w.findAll("form")[0].trigger("submit");
    await flushPromises();
    expect(api.put.mock.calls[0][1]).not.toHaveProperty("celular");
    w.unmount();
  });

  it("reacciona al rol activo, incluyendo roles personalizados", async () => {
    const w = montar();
    sesion.usuario.rol = "propietario";
    await flushPromises();
    expect(w.find('[data-prueba="privacidad"]').exists()).toBe(false);
    sesion.usuario.roles_disponibles = [
      { clave: "cliente-vip", faceta: "miembro" },
    ];
    sesion.usuario.rol = "cliente-vip";
    await flushPromises();
    expect(w.find('[data-prueba="privacidad"]').exists()).toBe(true);
    w.unmount();
  });
});
