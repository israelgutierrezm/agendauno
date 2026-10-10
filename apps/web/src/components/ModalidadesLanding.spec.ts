import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";

import es from "@/i18n/locales/es-MX";
import modalidadNegocio from "@/i18n/locales/modalidad.es-MX";
import { NOMBRE_MODALIDAD } from "@/marketing/modalidades";
import { rutasComerciales } from "@/router/comerciales";
import ModalidadesLanding from "./ModalidadesLanding.vue";
import fuente from "./ModalidadesLanding.vue?raw";

const Vacia = { render: () => null };

async function montar() {
  // Router real con las rutas comerciales: los href son los de producción.
  const router = createRouter({
    history: createMemoryHistory(),
    routes: rutasComerciales({
      landing: Vacia,
      modalidad: Vacia,
      solucion: Vacia,
    }),
  });
  await router.push("/");
  await router.isReady();
  const vista = mount(ModalidadesLanding, { global: { plugins: [router] } });
  return { vista, router };
}

describe("rutas comerciales por forma de trabajo", () => {
  it("presenta clases primero y después citas, con ejemplos identificados", async () => {
    const { vista } = await montar();
    expect(vista.findAll("h3").map((n) => n.text())).toEqual([
      "Clases con cupo",
      "Citas 1 a 1",
    ]);
    expect(vista.findAll("img")).toHaveLength(2);
    expect(vista.text()).toContain("Ejemplo de agenda");
    expect(vista.text()).toContain("Pole dance");
    expect(vista.findAll(".modalidad-reserva")).toHaveLength(2);
    expect(vista.find(".modalidad-cupos").exists()).toBe(false);
    expect(vista.find(".modalidad-horarios").exists()).toBe(false);
    expect(
      vista.findAll(".modalidad-imagen img").map((n) => n.attributes("src")),
    ).toEqual([
      "/assets/landing/disciplinas/pilates-v1.jpg",
      "/assets/landing/disciplinas/barberia-v1.jpg",
    ]);
    vista.unmount();
  });
  it("títulos de tarjeta con peso 500 y ventajas sin marcas de texto", async () => {
    // jsdom no aplica el CSS con scope: se revisa la regla del componente.
    expect(fuente).toMatch(/^h3 \{[^}]*font-weight: 500;/m);
    const { vista } = await montar();
    expect(vista.text()).not.toContain("✓");
    expect(vista.findAll("li svg.modalidad-check")).toHaveLength(6);
    vista.unmount();
  });
  it("nombra cada modalidad igual que los precios y el registro", () => {
    expect(modalidadNegocio.nombres).toEqual(NOMBRE_MODALIDAD);
    expect([es.nav.clases, es.nav.citas]).toEqual(["Clases", "Citas"]);
  });
  it("lleva a la landing de cada modalidad y avisa cuál se eligió", async () => {
    const { vista, router } = await montar();
    const enlaces = vista.findAll("a");
    expect(enlaces.map((n) => n.attributes("href"))).toEqual([
      "/clases",
      "/citas",
    ]);
    expect(enlaces.map((n) => n.text())).toEqual([
      "Conocer AgendaUno para clases",
      "Conocer AgendaUno para citas",
    ]);
    // La flecha es un ícono, no un carácter.
    enlaces.forEach((n) => expect(n.find("svg").exists()).toBe(true));
    await enlaces[1]!.trigger("click");
    await enlaces[0]!.trigger("click");
    expect(vista.emitted("elegir")).toEqual([["citas"], ["clases"]]);
    await router.isReady();
    await new Promise((resolver) => setTimeout(resolver));
    expect(router.currentRoute.value.path).toBe("/clases");
    vista.unmount();
  });
});
