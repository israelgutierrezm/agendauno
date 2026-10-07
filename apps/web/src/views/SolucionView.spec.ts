import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import { trackEvent } from "@/lib/analytics";
import {
  ETIQUETA_MENU,
  NOMBRE_MODALIDAD,
  modoDeGiro,
  perfilDeSolucion,
} from "@/marketing/modalidades";
import { rutaSolucion, soluciones } from "@/marketing/soluciones";
import { rutasComerciales } from "@/router/comerciales";
import SolucionView from "./SolucionView.vue";

/*
| Páginas por giro (/software-para-*): cada giro es de una sola modalidad (ADR 0104),
| así que enlazan a su página (/clases o /citas), a sus anclas y al registro con su
| `?modo=` (y su `?giro=` si la página es de un solo giro del registro), y no anuncian
| nada «en preparación».
*/

vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
beforeEach(() => vi.mocked(trackEvent).mockClear());

const vacia = { render: () => null };
// Las rutas comerciales reales y el registro, como en el prerender.
function routerComercial() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      ...rutasComerciales({
        landing: vacia,
        modalidad: vacia,
        solucion: vacia,
      }),
      { path: "/registro", name: "registro", component: vacia },
    ],
  });
}
async function montar(slug: string) {
  const router = routerComercial();
  await router.push(rutaSolucion(slug));
  await router.isReady();
  return mount(SolucionView, {
    props: { slug },
    global: { plugins: [router] },
  });
}
const hrefs = (vista: Awaited<ReturnType<typeof montar>>) =>
  vista.findAll("a").map((a) => a.attributes("href"));

describe("páginas por giro", () => {
  it("cada giro enlaza a su modalidad, a sus anclas y al registro con su modo", async () => {
    for (const solucion of soluciones) {
      const vista = await montar(solucion.slug);
      const modo = solucion.modo;
      expect(modoDeGiro(solucion.slug), solucion.slug).toBe(modo);
      expect(vista.findAll("h1")).toHaveLength(1);

      // Miga de pan: AgendaUno / Clases|Citas / giro.
      const miga = vista.get(".solucion-miga");
      expect(miga.findAll("a").map((a) => a.attributes("href"))).toEqual([
        "/",
        `/${modo}`,
      ]);
      expect(vista.get('[data-prueba="enlace-modalidad"]').text()).toBe(
        ETIQUETA_MENU[modo],
      );
      expect(miga.get('[aria-current="page"]').text()).toBe(solucion.nombre);

      const enlaces = hrefs(vista);
      // «Probar gratis» (arriba y al cierre) llega al registro con su modalidad y,
      // si la página es de un solo giro, con él.
      const giro = perfilDeSolucion(solucion.slug);
      const destino =
        giro === null
          ? `/registro?modo=${modo}`
          : `/registro?modo=${modo}&giro=${giro}`;
      expect(
        enlaces.filter((h) => h?.startsWith("/registro")),
        solucion.slug,
      ).toEqual([destino, destino]);
      expect(enlaces).toContain(`/${modo}#producto`);
      expect(enlaces).toContain(`/${modo}#precios`);
      // Ya no apunta a anclas de la portada.
      expect(enlaces).not.toContain("/#producto");
      expect(enlaces).not.toContain("/#precios");
      vista.unmount();
    }
  });

  it("el precio dice cómo se cobra cada modalidad, sin «en preparación»", async () => {
    for (const solucion of soluciones) {
      const vista = await montar(solucion.slug);
      const precio = vista.get('[data-prueba="solucion-precio"]').text();
      expect(precio).toContain(NOMBRE_MODALIDAD[solucion.modo]);
      expect(precio).toContain(
        solucion.modo === "clases"
          ? "por rango de alumnos activos al mes"
          : "por profesional activo al mes",
      );
      expect(precio).toContain(
        `Todo lo que incluye ${NOMBRE_MODALIDAD[solucion.modo]}`,
      );
      expect(vista.text()).not.toMatch(/en preparaci[oó]n/i);
      vista.unmount();
    }
  });

  it("en clases no promete que los alumnos se registren solos", async () => {
    const vista = await montar("pilates");
    const texto = vista.text();
    expect(texto).toContain("da de alta a tus alumnos, invítalos a su cuenta");
    expect(texto).not.toMatch(/se registran|crean su cuenta|reg[ií]strate/i);
    vista.unmount();
    const citas = await montar("barberias");
    expect(citas.text()).toContain(
      "para que tus clientes elijan servicio, profesional y horario",
    );
    citas.unmount();
  });

  it("los demás giros van en dos columnas, «Clases» y «Citas», sin la página actual", async () => {
    const vista = await montar("barberias");
    const columnas = vista.findAll(".soluciones-columna");
    expect(columnas.map((c) => c.attributes("data-modo"))).toEqual([
      "clases",
      "citas",
    ]);
    expect(columnas.map((c) => c.get("h3").text())).toEqual([
      ETIQUETA_MENU.clases,
      ETIQUETA_MENU.citas,
    ]);
    const citas = columnas[1]!
      .findAll(".soluciones-enlaces a")
      .map((a) => a.attributes("href"));
    expect(citas).not.toContain(rutaSolucion("barberias"));
    expect(citas.length).toBe(
      soluciones.filter((s) => s.modo === "citas").length - 1,
    );
    vista.unmount();
  });

  it("manda el giro solo si la página es de un giro del registro", async () => {
    const pilates = await montar("pilates");
    expect(pilates.get('[data-cta="hero"]').attributes("href")).toBe(
      "/registro?modo=clases&giro=pilates",
    );
    pilates.unmount();
    // «CrossFit y HYROX» junta dos giros: solo la modalidad.
    const hyrox = await montar("crossfit-hyrox");
    expect(hyrox.get('[data-cta="hero"]').attributes("href")).toBe(
      "/registro?modo=clases",
    );
    hyrox.unmount();
    // «Barberías y estéticas» junta dos giros: solo la modalidad.
    const barberias = await montar("barberias");
    expect(barberias.get('[data-cta="hero"]').attributes("href")).toBe(
      "/registro?modo=citas",
    );
    barberias.unmount();
  });

  it("mide los clics al registro con el giro, su modalidad (mode) y su perfil", async () => {
    const vista = await montar("terapeutas");
    const hero = vista.get('[data-cta="hero"]');
    expect(hero.attributes("href")).toBe("/registro?modo=citas&giro=salud");
    await hero.trigger("click");
    expect(trackEvent).toHaveBeenCalledWith("marketing_cta_clicked", {
      placement: "solution_hero",
      destination: "register",
      solution: "terapeutas",
      mode: "citas",
      business_profile: "salud",
    });
    vista.unmount();
    // Sin un solo giro, sin perfil.
    const barberias = await montar("barberias");
    await barberias.get('[data-cta="hero"]').trigger("click");
    expect(trackEvent).toHaveBeenLastCalledWith("marketing_cta_clicked", {
      placement: "solution_hero",
      destination: "register",
      solution: "barberias",
      mode: "citas",
    });
    barberias.unmount();
  });

  it("el botón del hero va en azul y el del cierre se queda rosa", async () => {
    const vista = await montar("pilates");
    expect(vista.get('[data-cta="hero"]').classes()).toEqual(
      expect.arrayContaining(["tu-btn-primario", "tu-btn-azul"]),
    );
    const cierre = vista.get(".solucion-cierre .tu-btn-primario");
    expect(cierre.classes()).not.toContain("tu-btn-azul");
    vista.unmount();
  });
});
