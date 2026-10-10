import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { createMemoryHistory, createRouter } from "vue-router";
import es from "@/i18n/locales/es-MX";
import { trackEvent } from "@/lib/analytics";
import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import {
  NOMBRE_MODALIDAD,
  modoDeGiro,
  perfilDeSolucion,
} from "@/marketing/modalidades";
import { PRECIOS_POR_OMISION } from "@/marketing/precios";
import { aplicarPreciosPublicos } from "@/marketing/preciosPublicos";
import {
  FRASES_SOLO_CON_REGISTRO,
  FRASES_SOLO_EN_PRELANZAMIENTO,
  frasesEncontradas,
} from "@/marketing/prelanzamiento";
import { rutaSolucion, soluciones } from "@/marketing/soluciones";
import { rutasComerciales } from "@/router/comerciales";
import SolucionView from "./SolucionView.vue";

/*
| Páginas por giro (/software-para-*): cada giro es de una sola modalidad (ADR 0104) y
| su página vive en el dominio de su producto (ADR 0108): enlazan a la portada de su
| producto, a sus anclas y al registro con su `?modo=` (y su `?giro=` si la página es
| de un solo giro del registro), hablan con su marca y no anuncian nada «en
| preparación».
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
        modalidad: vacia,
        solucion: vacia,
      }),
      { path: "/registro", name: "registro", component: vacia },
    ],
  });
}
// Desde la portada: en localhost rige AgendaUno, y las páginas de citas viven en el
// dominio de TurnoUno (su ruta aquí sale a él).
async function montar(slug: string) {
  const router = routerComercial();
  await router.push("/");
  await router.isReady();
  return mount(SolucionView, {
    props: { slug },
    global: {
      plugins: [
        router,
        createI18n({ legacy: false, locale: "es", messages: { es } }),
      ],
    },
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

      // Miga de pan: su marca / giro.
      const miga = vista.get(".solucion-miga");
      expect(miga.findAll("a").map((a) => a.attributes("href"))).toEqual(["/"]);
      expect(vista.get('[data-prueba="enlace-modalidad"]').text()).toBe(
        PRODUCTOS[productoDeModalidad(modo)].nombre,
      );
      // Ni la marca del otro producto.
      const otra =
        PRODUCTOS[productoDeModalidad(modo === "clases" ? "citas" : "clases")]
          .nombre;
      expect(vista.text(), solucion.slug).not.toContain(otra);
      expect(miga.get('li[aria-current="page"]').text()).toBe(solucion.nombre);

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
      // Las anclas de la portada de su producto.
      expect(enlaces).toContain("/#producto");
      expect(enlaces).toContain("/#precios");
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
          : "con el plan que elijas, por los profesionales que contratas",
      );
      expect(precio).toContain(
        `Todo lo que incluye ${PRODUCTOS[productoDeModalidad(solucion.modo)].nombre}`,
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

  it("los demás giros son los de su producto, sin la página actual", async () => {
    const vista = await montar("barberias");
    const enlaces = vista
      .findAll(".soluciones-enlaces a")
      .map((a) => a.attributes("href"));
    expect(enlaces).not.toContain(rutaSolucion("barberias"));
    expect(enlaces).toEqual(
      soluciones
        .filter((s) => s.modo === "citas" && s.slug !== "barberias")
        .map((s) => rutaSolucion(s.slug)),
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

  it("con el registro abierto (los dos productos): la prueba, sin nada de la lista de interesados", async () => {
    for (const solucion of soluciones) {
      const vista = await montar(solucion.slug);
      expect(vista.get('[data-cta="hero"]').text()).toBe(
        es.landing.solucion.probar,
      );
      expect(vista.text()).toContain("30 días para probarlo · Sin tarjeta");
      expect(vista.text()).toContain(es.landing.solucion.empezar.etiqueta);
      expect(
        frasesEncontradas(vista.text(), FRASES_SOLO_EN_PRELANZAMIENTO),
        solucion.slug,
      ).toEqual([]);
      vista.unmount();
    }
  });

  it("con el registro cerrado por el superadmin dice cómo funcionará y pide los datos, sin prueba ni registro", async () => {
    aplicarPreciosPublicos({ registro: { agendauno: false, turnouno: false } });
    try {
      for (const solucion of soluciones) {
        const vista = await montar(solucion.slug);
        const texto = vista.text();
        expect(vista.get('[data-cta="hero"]').text()).toBe(
          es.landing.prelanzamiento.cta,
        );
        expect(vista.get('[data-cta="final"]').text()).toBe(
          es.landing.prelanzamiento.cta,
        );
        expect(texto).toContain(es.landing.solucion.prelanzamiento.etiqueta);
        expect(texto).toContain(
          `${PRODUCTOS[productoDeModalidad(solucion.modo)].nombre} abre pronto.`,
        );
        expect(
          frasesEncontradas(texto, FRASES_SOLO_CON_REGISTRO),
          solucion.slug,
        ).toEqual([]);
        for (const frase of [
          /Del registro a tu primera reserva/,
          /Una prueba con tu operación real/,
          /antes de contratar/,
        ]) {
          expect(texto, `${solucion.slug}: ${String(frase)}`).not.toMatch(
            frase,
          );
        }
        // Lleva a la lista de interesados: se mide como `waitlist`.
        await vista.get('[data-cta="final"]').trigger("click");
        expect(trackEvent).toHaveBeenLastCalledWith(
          "marketing_cta_clicked",
          expect.objectContaining({
            placement: "solution_final",
            destination: "waitlist",
            solution: solucion.slug,
          }),
        );
        vista.unmount();
      }
    } finally {
      aplicarPreciosPublicos(PRECIOS_POR_OMISION);
    }
  });

  it("nombra el giro a media frase en minúscula y sin «academia» en todos los giros", async () => {
    const barberias = await montar("barberias");
    expect(barberias.get(".solucion-etiqueta").text()).toBe(
      "Agenda de citas para barberías y estéticas",
    );
    expect(barberias.get("#beneficios-titulo").text()).toBe(
      es.landing.solucion.beneficiosTitulo,
    );
    barberias.unmount();
    const crossfit = await montar("crossfit-hyrox");
    expect(crossfit.get(".solucion-etiqueta").text()).toBe(
      "Software de reservas para centros de CrossFit y HYROX",
    );
    expect(crossfit.get("#beneficios-titulo").text()).not.toMatch(/academia/);
    crossfit.unmount();
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
