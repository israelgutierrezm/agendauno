import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import PublicShell from "./PublicShell.vue";
import LogoAgendaUno from "./LogoAgendaUno.vue";

vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
beforeEach(() => {
  setActivePinia(createPinia());
});
function montar(props = {}) {
  return mount(PublicShell, {
    props,
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
      stubs: { RouterLink: { template: "<a><slot /></a>" } },
    },
  });
}
describe("encabezado público", () => {
  it("con sesión ofrece volver a su panel, no iniciar sesión", () => {
    const sesion = useSesionTenantStore();
    sesion.$patch({
      bearer: "simulado",
      usuario: {
        ulid: "u1",
        nombre: "Dueño",
        email: "duena@demo.test",
        rol: "propietario",
        permisos: ["*"],
      },
    });
    const vista = montar({ pagina: "directorio" });
    expect(vista.get(".tu-public-nav .tu-btn-fantasma").text()).toContain(
      "Ir a mi panel",
    );
    expect(vista.text()).not.toContain("Iniciar sesión");
  });
  it("evita repetir el CTA comercial en registro y acceso", () => {
    for (const pagina of [
      "registro",
      "entrar",
      "directorio",
      "activar",
      "recuperar-contrasena",
      "confirmar-correo",
      "aviso-privacidad",
    ]) {
      const vista = montar({ pagina, esAcceso: pagina === "entrar" });
      expect(vista.find(".tu-public-nav").exists()).toBe(true);
      expect(vista.find(".tu-public-register").exists()).toBe(false);
      expect(vista.find(".tu-public-sections").exists()).toBe(false);
      expect(vista.get(".tu-public-back").text()).toBe("Inicio");
      expect(vista.find(".tu-public-theme").exists()).toBe(true);
      vista.unmount();
    }
  });
  it("conserva navegación y CTA en las páginas comerciales", () => {
    for (const pagina of ["inicio", "solucion-pilates"]) {
      const vista = montar({ pagina });
      expect(vista.find(".tu-public-register").exists()).toBe(true);
      expect(vista.find(".tu-public-sections").exists()).toBe(true);
      vista.unmount();
    }
  });
  it("enlaza el aviso de privacidad desde el footer comercial", () => {
    const vista = montar();
    expect(vista.get('footer a[to="/aviso-de-privacidad"]').text()).toBe(
      "Aviso de privacidad",
    );
    expect(vista.get("footer").text()).toContain(
      "Cada negocio presta y administra sus propios servicios",
    );
    vista.unmount();
  });
  it("muestra el nuevo logo dentro de una barra compacta", () => {
    const vista = montar();
    expect(vista.findComponent(LogoAgendaUno).props()).toMatchObject({
      ancho: 192,
      variante: "horizontal",
    });
    expect(vista.get(".tu-public-container").classes()).toContain("min-h-18");
    expect(vista.get(".tu-public-container").classes()).toContain("py-2");
    expect(vista.classes()).toContain("tu-marketing");
    vista.unmount();
  });
  it("conserva el control de tema independiente y accesible", async () => {
    const vista = montar({ esOscuro: true });
    expect(vista.get(".tu-public-theme").attributes("aria-label")).toBe(
      es.tema.claro,
    );
    await vista.get(".tu-public-theme").trigger("click");
    expect(vista.emitted("alternarTema")).toHaveLength(1);
    vista.unmount();
  });
  it("comparte colores públicos sin imponer navegación comercial al negocio", () => {
    const vista = montar({ esRutaPublicaDeNegocio: true });
    expect(vista.classes()).not.toContain("tu-marketing");
    expect(vista.classes()).toContain("tu-public-business");
    expect(vista.find(".tu-public-nav").exists()).toBe(false);
    vista.unmount();
  });
});
