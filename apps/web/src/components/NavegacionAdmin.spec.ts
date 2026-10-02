import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";
import { defineComponent, h } from "vue";
import { createI18n } from "vue-i18n";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";

import esMX from "@/i18n/locales/es-MX";
import { POLITICAS } from "@/lib/acceso";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useUbicacionActual } from "@/lib/ubicacionActual";
import { i18n as i18nApp } from "@/i18n";
import LayoutConfiguracion from "./LayoutConfiguracion.vue";
import NavLateral from "./NavLateral.vue";
import PestanasArea from "./PestanasArea.vue";

/*
| La navegación del dueño (documento de reformulación): grupos con un enlace por área,
| las vistas del área en pestañas y Configuración con su navegación secundaria.
*/

const Vacia = { render: () => h("div", "pantalla") };

function crearRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: Object.keys(POLITICAS).map((name) => ({
      path: `/${name}`,
      name,
      component: Vacia,
    })),
  });
}

function i18n() {
  return createI18n({
    legacy: false,
    locale: "es",
    missingWarn: false,
    fallbackWarn: false,
    messages: { es: esMX },
  });
}

async function montar(
  componente: unknown,
  ruta: { name: string; query?: Record<string, string>; hash?: string },
) {
  const router = crearRouter();
  await router.push(ruta);
  const w = mount(
    {
      render: () =>
        h("div", [h(componente as never, { compacto: false }), h(RouterView)]),
    },
    { global: { plugins: [router, i18n()] } },
  );
  await flushPromises();
  return { w, router };
}

function entrar(
  permisos: string[],
  modalidad: "clases" | "citas" = "clases",
): void {
  const sesion = useSesionTenantStore();
  sesion.usuario = { rol: "admin", permisos } as never;
  sesion.estudio = {
    perfil_config: { modalidad, flags: { grupos: false } },
  } as never;
}

beforeEach(() => {
  setActivePinia(createPinia());
});

describe("barra lateral", () => {
  it("grupos con títulos y un enlace por área; solo uno activo", async () => {
    entrar(["*"]);
    const { w } = await montar(NavLateral, { name: "ficha-miembro" });

    const titulos = w.findAll(".nl-titulo").map((t) => t.text());
    expect(titulos).toEqual(["Operación diaria", "Gestión", "Configuración"]);
    const activas = w.findAll('[aria-current="page"]');
    expect(activas).toHaveLength(1);
    // La ficha de un alumno deja activa el área de alumnos.
    expect(activas[0].attributes("data-prueba")).toBe("area-clientes");
  });

  it("sin permiso de un área, no aparece; el grupo vacío tampoco", async () => {
    entrar(["ordenes.ver"]);
    const { w } = await montar(NavLateral, { name: "facturas" });

    expect(w.findAll(".nl-titulo").map((t) => t.text())).toEqual([
      "Operación diaria",
    ]);
    expect(w.find('[data-prueba="area-cobros"]').attributes("href")).toBe(
      "/facturas",
    );
  });
});

describe("pestañas del área", () => {
  it("las vistas permitidas del área, con la actual marcada", async () => {
    entrar(["agenda.ver", "reservas.gestionar"]);
    const { w } = await montar(PestanasArea, { name: "recepcion" });

    const pestanas = w.findAll(".pa-pestanas a");
    expect(pestanas.map((p) => p.text())).toEqual([
      "Calendario",
      "Recepción",
      "Lugares disponibles",
    ]);
    expect(w.find('.pa-pestanas [aria-current="page"]').text()).toBe(
      "Recepción",
    );
  });

  it("Ventas: con clases abre en los planes; con citas, en el mostrador", async () => {
    entrar(["*"]);
    const clases = await montar(PestanasArea, { name: "pos" });
    const rutas = (w: typeof clases.w) =>
      w.findAll(".pa-pestanas a").map((p) => p.attributes("href"));
    expect(rutas(clases.w)).toEqual(["/ventas", "/pos", "/inventario"]);

    entrar(["*"], "citas");
    const citas = await montar(PestanasArea, { name: "pos" });
    expect(rutas(citas.w)).toEqual(["/pos", "/inventario", "/ventas"]);
    expect(citas.w.findAll(".pa-pestanas a").at(-1)!.text()).toBe(
      "Bonos y membresías",
    );
    const lateral = await montar(NavLateral, { name: "pos" });
    expect(
      lateral.w.find('[data-prueba="area-ventas"]').attributes("href"),
    ).toBe("/pos");
  });

  it("con una sola vista no hay pestañas", async () => {
    entrar(["reservas.gestionar"]);
    const { w } = await montar(PestanasArea, { name: "recepcion" });

    // Recepción y Lugares disponibles: dos vistas.
    expect(w.find('[data-prueba="pestanas-area"]').exists()).toBe(true);
    entrar(["facturacion.ver"]);
    const otra = await montar(PestanasArea, { name: "reportes" });
    expect(otra.w.find('[data-prueba="pestanas-area"]').exists()).toBe(false);
  });
});

describe("configuración del negocio", () => {
  it("abre la categoría de la opción actual", async () => {
    entrar(["*"]);
    const { w } = await montar(LayoutConfiguracion, { name: "pasarelas" });

    const abierta = w.find('.lc-lateral [aria-expanded="true"]');
    expect(abierta.text()).toBe("Pagos e integraciones");
    expect(w.find('.lc-lateral [aria-current="page"]').text()).toBe(
      "Pasarelas de pago",
    );
  });

  it("solo las categorías con opciones permitidas", async () => {
    entrar(["pagos.configurar", "roles.gestionar"]);
    const { w } = await montar(LayoutConfiguracion, { name: "roles" });

    expect(w.findAll(".lc-lateral .lc-categoria").map((c) => c.text())).toEqual(
      ["Pagos e integraciones", "Accesos y permisos"],
    );
  });

  it("«Buscar ajuste» encuentra por sinónimos y solo lo permitido", async () => {
    entrar(["*"]);
    const { w } = await montar(LayoutConfiguracion, { name: "configuracion" });

    await w.get(".lc-lateral input").setValue("logo");
    expect(w.findAll(".lc-lateral .lc-resultado").map((r) => r.text())).toEqual(
      ["Datos e imagen del negocioNegocio"],
    );
    await w.get(".lc-lateral input").setValue("conectar pagos");
    expect(w.find(".lc-lateral .lc-resultado").text()).toContain(
      "Pasarelas de pago",
    );
  });
});

// La barra superior: el área y la ruta de ubicación (enlaces menos el último).
const BarraUbicacion = defineComponent({
  setup() {
    const lugar = useUbicacionActual();
    return () =>
      h("div", [
        h("p", { class: "titulo" }, lugar.titulo.value),
        ...lugar.migas.value.map((m) =>
          h("span", { class: m.destino ? "miga enlace" : "miga" }, m.texto),
        ),
      ]);
  },
});

// Con los textos completos de la app (las áreas usan claves de varios archivos).
async function montarBarra(ruta: {
  name: string;
  query?: Record<string, string>;
}) {
  const router = crearRouter();
  await router.push(ruta);
  const w = mount(BarraUbicacion, { global: { plugins: [router, i18nApp] } });
  await flushPromises();
  return w;
}

describe("barra superior", () => {
  it("en Configuración: el área, la categoría y la opción", async () => {
    entrar(["*"]);
    const w = await montarBarra({ name: "pasarelas" });

    expect(w.get(".titulo").text()).toBe("Configuración del negocio");
    expect(w.findAll(".miga").map((m) => m.text())).toEqual([
      "Configuración del negocio",
      "Pagos e integraciones",
      "Pasarelas de pago",
    ]);
    // Solo el área se abre; la categoría no es una pantalla y la última es esta.
    expect(w.findAll(".miga.enlace").map((m) => m.text())).toEqual([
      "Configuración del negocio",
    ]);
  });

  it("en un área: su nombre (con el término del negocio) y la vista", async () => {
    entrar(["*"]);
    const w = await montarBarra({
      name: "cobranza",
      query: { vista: "caja" },
    });

    expect(w.get(".titulo").text()).toBe("Cobros");
    expect(w.findAll(".miga").map((m) => m.text())).toEqual(["Cobros", "Caja"]);
  });

  it("en una ficha: el área, la vista de la que es y «Ficha»", async () => {
    entrar(["*"]);
    const w = await montarBarra({ name: "ficha-miembro" });

    expect(w.get(".titulo").text()).toBe("Miembros");
    expect(w.findAll(".miga").map((m) => m.text())).toEqual([
      "Miembros",
      "Directorio",
      "Ficha",
    ]);
    expect(w.findAll(".miga.enlace").map((m) => m.text())).toEqual([
      "Miembros",
      "Directorio",
    ]);
  });
});
