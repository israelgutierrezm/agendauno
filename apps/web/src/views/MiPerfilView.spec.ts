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
const sesion = reactive({ slug: "demo", usuario: estado.usuario });
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
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
  sesion.usuario.rol = "miembro";
  sesion.usuario.roles_disponibles = [{ clave: "miembro", faceta: "miembro" }];
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
