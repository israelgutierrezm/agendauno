import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import { miPerfil } from "@/i18n/locales/equipo.es-MX";
import operacion from "@/i18n/locales/operacion.es-MX";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import MenuPerfil from "./MenuPerfil.vue";

function montar(abierto: boolean) {
  return mount(MenuPerfil, {
    props: { abierto },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...es, operacion, miPerfil } },
        }),
      ],
      stubs: { RouterLink: { template: "<a><slot /></a>" } },
    },
  });
}

function entrarComo(rol: string, permisos: string[]): void {
  const sesion = useSesionTenantStore();
  sesion.usuario = {
    ulid: "u1",
    nombre: "Renata Soto",
    email: "renata.soto@correo.test",
    rol,
    roles: [rol],
    roles_disponibles: [
      { clave: rol, faceta: rol === "miembro" ? "miembro" : "equipo" },
    ],
    permisos,
  } as never;
}

describe("menú de perfil", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("el botón muestra sus iniciales, su nombre y su rol, y alterna el menú", async () => {
    entrarComo("miembro", []);
    const w = montar(false);
    const boton = w.get(".tu-perfil-boton");
    expect(boton.text()).toContain("RS");
    expect(boton.text()).toContain("Renata Soto");
    expect(boton.text()).toContain("Miembro");
    expect(w.find('[role="menu"]').exists()).toBe(false);
    await boton.trigger("click");
    expect(w.emitted("alternar")).toHaveLength(1);
  });

  it("abierto: su nombre, correo y rol; Mi perfil y cerrar sesión (sin configuración para un miembro)", async () => {
    entrarComo("miembro", []);
    const w = montar(true);
    const menu = w.get('[role="menu"]');
    expect(menu.get(".tu-perfil-cabecera").text()).toContain(
      "renata.soto@correo.test",
    );
    expect(menu.get(".tu-perfil-rol").text()).toBe("Miembro");
    const opciones = menu.findAll('[role="menuitem"]').map((o) => o.text());
    expect(opciones).toEqual(["Mi perfil", "Cerrar sesión"]);

    await menu.findAll('[role="menuitem"]')[1].trigger("click");
    expect(w.emitted("salir")).toHaveLength(1);
  });

  it("quien puede configurar el negocio ve también Configuración", () => {
    entrarComo("propietario", ["*"]);
    const w = montar(true);
    const opciones = w.findAll('[role="menuitem"]').map((o) => o.text());
    expect(opciones).toEqual(["Mi perfil", "Configuración", "Cerrar sesión"]);
  });
});
