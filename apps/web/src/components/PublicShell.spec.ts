import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { createMemoryHistory, createRouter } from "vue-router";
import es from "@/i18n/locales/es-MX";
import { trackEvent } from "@/lib/analytics";
import { PRECIOS_POR_OMISION } from "@/marketing/precios";
import { aplicarPreciosPublicos } from "@/marketing/preciosPublicos";
import { rutasComerciales } from "@/router/comerciales";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import PublicShell from "./PublicShell.vue";
import LogoAgendaUno from "./LogoAgendaUno.vue";

vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
beforeEach(() => {
  setActivePinia(createPinia());
  vi.mocked(trackEvent).mockClear();
});

const vacia = { render: () => null };
// Las rutas comerciales reales (meta.marketing y meta.modo) y las de acceso.
function routerPublico() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      ...rutasComerciales({
        modalidad: vacia,
        solucion: vacia,
      }),
      ...[
        "registro",
        "entrar",
        "directorio",
        "activar",
        "recuperar-contrasena",
        "confirmar-correo",
        "aviso-privacidad",
        "panel",
      ].map((name) => ({
        path: name === "aviso-privacidad" ? "/aviso-de-privacidad" : `/${name}`,
        name,
        component: vacia,
      })),
    ],
  });
}
async function montar(props = {}, ruta = "/") {
  const router = routerPublico();
  await router.push(ruta);
  await router.isReady();
  return mount(PublicShell, {
    props,
    global: {
      plugins: [
        router,
        createI18n({ legacy: false, locale: "es", messages: { es } }),
      ],
    },
  });
}
function enlaces(vista: Awaited<ReturnType<typeof montar>>, selector: string) {
  return vista
    .findAll(`${selector} a`)
    .map((a) => [a.text(), a.attributes("href")]);
}

describe("encabezado público", () => {
  it("con sesión ofrece volver a su panel, no iniciar sesión", async () => {
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
    const vista = await montar({ pagina: "directorio" }, "/directorio");
    expect(vista.get(".tu-public-nav .tu-btn-fantasma").text()).toContain(
      "Ir a mi panel",
    );
    expect(vista.text()).not.toContain("Iniciar sesión");
  });
  it("evita repetir el CTA comercial en registro y acceso", async () => {
    for (const pagina of [
      "registro",
      "entrar",
      "directorio",
      "activar",
      "recuperar-contrasena",
      "confirmar-correo",
      "aviso-privacidad",
    ]) {
      const ruta =
        pagina === "aviso-privacidad" ? "/aviso-de-privacidad" : `/${pagina}`;
      const vista = await montar(
        { pagina, esAcceso: pagina === "entrar" },
        ruta,
      );
      expect(vista.find(".tu-public-nav").exists()).toBe(true);
      expect(vista.find(".tu-public-register").exists()).toBe(false);
      expect(vista.find(".tu-public-sections").exists()).toBe(false);
      expect(vista.get(".tu-public-back").text()).toBe("Inicio");
      expect(vista.find(".tu-public-theme").exists()).toBe(true);
      vista.unmount();
    }
  });
  it("es comercial por la ruta (meta.marketing), no por el nombre que recibe", async () => {
    for (const ruta of ["/", "/clases", "/software-para-pilates"]) {
      // Sin `pagina`, como en el prerender.
      const vista = await montar({}, ruta);
      expect(vista.find(".tu-public-register").exists(), ruta).toBe(true);
      expect(vista.findAll(".tu-public-sections")).toHaveLength(2);
      expect(vista.find(".tu-public-back").exists()).toBe(false);
      vista.unmount();
    }
    // Un nombre «comercial» en una ruta que no lo es no muestra el menú.
    const vista = await montar({ pagina: "inicio" }, "/registro");
    expect(vista.find(".tu-public-sections").exists()).toBe(false);
    vista.unmount();
  });
  it("menú del producto: Funciones · Precios · Preguntas de su portada (ADR 0108)", async () => {
    const esperado = [
      ["Funciones", "/#soluciones"],
      ["Precios", "/#precios"],
      ["Preguntas", "/#preguntas"],
    ];
    for (const ruta of ["/", "/software-para-pilates"]) {
      const vista = await montar({}, ruta);
      expect(enlaces(vista, ".tu-public-nav .tu-public-sections")).toEqual(
        esperado,
      );
      // La segunda fila del móvil, fuera de la barra fija y sin JS.
      expect(enlaces(vista, ".tu-public-sections-fila")).toEqual(esperado);
      expect(
        vista.find(".tu-public-nav .tu-public-sections-fila").exists(),
      ).toBe(false);
      // Sin la otra modalidad: es otro producto, en su dominio.
      expect(vista.text()).not.toContain("Citas");
      vista.unmount();
    }
  });
  it("«Probar gratis» lleva la modalidad de la página al registro y a la medición", async () => {
    const portada = await montar({}, "/");
    const boton = portada.get(".tu-public-register");
    expect(boton.text()).toContain("Probar gratis");
    expect(boton.attributes("href")).toBe("/registro?modo=clases");
    await boton.trigger("click");
    await flushPromises();
    expect(trackEvent).toHaveBeenCalledWith("marketing_cta_clicked", {
      placement: "navigation",
      destination: "register",
      mode: "clases",
    });
    portada.unmount();

    // Página de un solo giro: el registro llega con el giro ya elegido, igual que
    // los «Probar gratis» del hero y del cierre.
    vi.mocked(trackEvent).mockClear();
    const pilates = await montar({}, "/software-para-pilates");
    const conGiro = pilates.get(".tu-public-register");
    expect(conGiro.attributes("href")).toBe(
      "/registro?modo=clases&giro=pilates",
    );
    await conGiro.trigger("click");
    expect(trackEvent).toHaveBeenCalledWith("marketing_cta_clicked", {
      placement: "navigation",
      destination: "register",
      mode: "clases",
      business_profile: "pilates",
    });
    pilates.unmount();
  });
  it("si el producto aún no recibe registros, el botón ofrece avisar", async () => {
    aplicarPreciosPublicos({ registro: { agendauno: false, turnouno: false } });
    try {
      const vista = await montar({}, "/");
      const boton = vista.get(".tu-public-register");
      expect(boton.text()).toContain("Quiero que me avisen");
      // Lleva a la lista de interesados: se mide como `waitlist`.
      await boton.trigger("click");
      expect(trackEvent).toHaveBeenLastCalledWith("marketing_cta_clicked", {
        placement: "navigation",
        destination: "waitlist",
        mode: "clases",
      });
      // Aún no hay negocios que buscar: sin «¿Buscas reservar?».
      expect(vista.find('footer a[href="/directorio"]').exists()).toBe(false);
      expect(vista.get("footer").text()).not.toContain(es.nav.encontrarNegocio);
      vista.unmount();
    } finally {
      aplicarPreciosPublicos(PRECIOS_POR_OMISION);
    }
  });
  it("con el registro abierto, el footer ofrece encontrar un negocio", async () => {
    const vista = await montar({}, "/");
    expect(vista.get('footer a[href="/directorio"]').text()).toBe(
      es.nav.encontrarNegocio,
    );
    vista.unmount();
  });
  it("enlaza el aviso de privacidad desde el footer comercial", async () => {
    const vista = await montar();
    expect(vista.get('footer a[href="/aviso-de-privacidad"]').text()).toBe(
      "Aviso de privacidad",
    );
    expect(vista.get("footer").text()).toContain(
      "Cada negocio presta y administra sus propios servicios",
    );
    vista.unmount();
  });
  it("muestra el nuevo logo dentro de una barra compacta", async () => {
    const vista = await montar();
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
    const vista = await montar({ esOscuro: true });
    expect(vista.get(".tu-public-theme").attributes("aria-label")).toBe(
      es.tema.claro,
    );
    await vista.get(".tu-public-theme").trigger("click");
    expect(vista.emitted("alternarTema")).toHaveLength(1);
    vista.unmount();
  });
  it("comparte colores públicos sin imponer navegación comercial al negocio", async () => {
    const vista = await montar({ esRutaPublicaDeNegocio: true });
    expect(vista.classes()).not.toContain("tu-marketing");
    expect(vista.classes()).toContain("tu-public-business");
    expect(vista.find(".tu-public-nav").exists()).toBe(false);
    expect(vista.find(".tu-public-sections").exists()).toBe(false);
    vista.unmount();
  });
});
