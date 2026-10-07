import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";

import { solucionesDe, type Modo } from "@/marketing/modalidades";
import { rutaSolucion, soluciones } from "@/marketing/soluciones";
import { rutasComerciales } from "@/router/comerciales";
import SolucionesEnlaces from "./SolucionesEnlaces.vue";

const Vacia = { render: () => null };

async function montar(
  props: { excluir?: string; modo?: Modo; dosColumnas?: boolean } = {},
  slots: Record<string, string> = {},
) {
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
  return mount(SolucionesEnlaces, {
    props,
    slots,
    global: { plugins: [router] },
  });
}
const hrefs = (vista: Awaited<ReturnType<typeof montar>>, selector = "a") =>
  vista.findAll(selector).map((n) => n.attributes("href"));

describe("enlaces a las páginas por giro", () => {
  it("sin props enlaza todas las páginas por giro, como siempre", async () => {
    const vista = await montar();
    expect(hrefs(vista)).toEqual(soluciones.map((s) => rutaSolucion(s.slug)));
    expect(vista.find("h3").exists()).toBe(false);
    vista.unmount();
  });
  it("excluye la página actual", async () => {
    const vista = await montar({ excluir: "pilates" });
    expect(hrefs(vista)).not.toContain("/software-para-pilates");
    expect(hrefs(vista)).toHaveLength(soluciones.length - 1);
    vista.unmount();
  });
  it("con modo enlaza solo los giros de esa modalidad", async () => {
    for (const modo of ["clases", "citas"] as const) {
      const vista = await montar({ modo });
      const propios = solucionesDe(modo).map((s) => rutaSolucion(s.slug));
      expect(propios.length).toBeGreaterThan(0);
      expect(hrefs(vista)).toEqual(propios);
      expect(vista.get("nav").attributes("data-modo")).toBe(modo);
      vista.unmount();
    }
    const citas = await montar({ modo: "citas", excluir: "spas" });
    expect(hrefs(citas)).not.toContain("/software-para-spas");
    expect(hrefs(citas)).not.toContain("/software-para-pilates");
    citas.unmount();
  });
  it("en dos columnas separa «Clases» y «Citas», cada una con sus giros y su pie", async () => {
    const vista = await montar(
      { dosColumnas: true },
      { pie: '<p class="pie">Pie de {{ params.modo }}</p>' },
    );
    expect(vista.findAll("nav")).toHaveLength(1);
    expect(
      vista.findAll(".soluciones-columna h3").map((n) => n.text()),
    ).toEqual(["Clases", "Citas"]);
    for (const modo of ["clases", "citas"] as const) {
      const columna = vista.get(`.soluciones-columna[data-modo="${modo}"]`);
      const titulo = columna.get("h3");
      expect(columna.get("ul").attributes("aria-labelledby")).toBe(
        titulo.attributes("id"),
      );
      expect(columna.findAll("li a").map((n) => n.attributes("href"))).toEqual(
        solucionesDe(modo).map((s) => rutaSolucion(s.slug)),
      );
      expect(columna.get(".pie").text()).toBe(`Pie de ${modo}`);
    }
    // Los ids de las dos columnas no se repiten.
    const ids = vista.findAll("h3").map((n) => n.attributes("id"));
    expect(new Set(ids).size).toBe(2);
    vista.unmount();
  });
});
