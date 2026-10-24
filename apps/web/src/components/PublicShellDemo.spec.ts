import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ref } from "vue";
import { createMemoryHistory, createRouter } from "vue-router";

import { i18n } from "@/i18n";
import { trackEvent } from "@/lib/analytics";
import { fijarProductoDeSesion } from "@/lib/producto";
import { rutasComerciales } from "@/router/comerciales";
import PublicShell from "./PublicShell.vue";

/*
| «Agendar una demo» en la cabecera y el pie públicos (ADR 0204), como en la competencia:
| solo si la plataforma agenda demos y nunca en la página de un negocio.
*/

vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
const demos = vi.hoisted(() => ({ activas: true, preguntadas: 0 }));
vi.mock("@/lib/demos", () => ({
  useDemosActivas: (cuando: () => boolean = () => true) => {
    const quiere = cuando();
    if (quiere) {
      demos.preguntadas++;
    }
    return ref(quiere && demos.activas);
  },
}));

const vacia = { render: () => null };
async function montar(props = {}, ruta = "/") {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      ...rutasComerciales({ modalidad: vacia, solucion: vacia }),
      ...["registro", "entrar", "directorio", "demo"].map((name) => ({
        path: `/${name}`,
        name,
        component: vacia,
      })),
      { path: "/aviso-de-privacidad", name: "aviso", component: vacia },
      { path: "/terminos", name: "terminos", component: vacia },
    ],
  });
  await router.push(ruta);
  await router.isReady();
  const vista = mount(PublicShell, {
    props,
    global: { plugins: [router, i18n] },
  });
  await flushPromises();
  return vista;
}

beforeEach(() => {
  setActivePinia(createPinia());
  fijarProductoDeSesion(null);
  vi.mocked(trackEvent).mockClear();
  demos.activas = true;
  demos.preguntadas = 0;
});

describe("demo en la cabecera y el pie públicos", () => {
  it("con demos, el menú comercial y el pie llevan a la página de la demo", async () => {
    const vista = await montar({}, "/");
    const enlace = vista.get('[data-cta="nav_demo"]');
    expect(enlace.text()).toBe("Agendar una demo");
    expect(enlace.attributes("href")).toBe("/demo?producto=agendauno");
    expect(vista.get('[data-prueba="pie-demo"]').attributes("href")).toBe(
      "/demo?producto=agendauno",
    );

    await enlace.trigger("click");
    expect(trackEvent).toHaveBeenCalledWith(
      "marketing_cta_clicked",
      expect.objectContaining({ placement: "navigation", destination: "demo" }),
    );
  });

  it("sin demos no aparecen", async () => {
    demos.activas = false;
    const vista = await montar({}, "/");
    expect(vista.find('[data-cta="nav_demo"]').exists()).toBe(false);
    expect(vista.find('[data-prueba="pie-demo"]').exists()).toBe(false);
  });

  it("en la página de un negocio ni siquiera se pregunta", async () => {
    const vista = await montar({ esRutaPublicaDeNegocio: true }, "/registro");
    expect(demos.preguntadas).toBe(0);
    expect(vista.find('[data-prueba="pie-demo"]').exists()).toBe(false);
  });
});
