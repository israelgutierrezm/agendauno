import { existsSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

import es from "@/i18n/locales/es-MX";
import modalidadNegocio from "@/i18n/locales/modalidad.es-MX";
import {
  GIROS_POR_MODALIDAD,
  girosDe as girosDeNegocio,
} from "@/lib/modalidad";
import {
  ETIQUETA_MENU,
  MODALIDADES,
  MODOS,
  NEGOCIOS_POR_MODO,
  NOMBRE_MODALIDAD,
  NOMBRE_RUTA_MODALIDAD,
  PERFILES_GENERALES,
  PERFILES_POR_MODO,
  PERFIL_DE_NEGOCIO,
  PERFIL_DE_SOLUCION,
  giroDeQuery,
  girosDe,
  modoDeGiro,
  modoDePerfil,
  modoDeQuery,
  negociosDe,
  perfilDeNegocio,
  perfilDeSolucion,
  perfilVisibleAlPublico,
  rutaModalidad,
  solucionesDe,
} from "./modalidades";
import { soluciones } from "./soluciones";

const publico = (ruta: string) => resolve(process.cwd(), `public${ruta}`);

describe("modalidades comerciales", () => {
  it("normaliza el ?modo= y descarta lo que no es una modalidad", () => {
    expect(modoDeQuery("clases")).toBe("clases");
    expect(modoDeQuery(" Citas ")).toBe("citas");
    expect(modoDeQuery(["citas", "clases"])).toBe("citas");
    for (const invalido of ["", "clase", "mixto", null, undefined, 1, [null]]) {
      expect(modoDeQuery(invalido)).toBeNull();
    }
  });

  it("cada modalidad tiene su ruta, su nombre de ruta y un solo nombre", () => {
    expect(MODOS).toEqual(["clases", "citas"]);
    expect(MODOS.map(rutaModalidad)).toEqual(["/clases", "/citas"]);
    expect(NOMBRE_RUTA_MODALIDAD).toEqual({
      clases: "modalidad-clases",
      citas: "modalidad-citas",
    });
    // El mismo par de nombres en la portada, los precios y el registro.
    expect(NOMBRE_MODALIDAD).toEqual(modalidadNegocio.nombres);
    expect(ETIQUETA_MENU).toEqual({
      clases: es.nav.clases,
      citas: es.nav.citas,
    });
    for (const modo of MODOS) {
      const contenido = MODALIDADES[modo];
      expect(contenido.modo).toBe(modo);
      expect(contenido.ruta).toBe(rutaModalidad(modo));
      expect(contenido.nombreRuta).toBe(NOMBRE_RUTA_MODALIDAD[modo]);
      expect(contenido.nombre).toBe(NOMBRE_MODALIDAD[modo]);
      expect(contenido.seo.description.length).toBeGreaterThan(70);
      // Sin la frase de cierre: la pone seoConfig según el registro del producto.
      expect(contenido.seo.description).not.toMatch(/gratis|sin tarjeta|días/i);
    }
  });

  it("usa imágenes que ya existen en public/", () => {
    for (const modo of MODOS) {
      const { seo, fotos } = MODALIDADES[modo];
      for (const src of [seo.imagen, fotos.recepcion.src]) {
        expect(existsSync(publico(src)), src).toBe(true);
      }
      expect(fotos.recepcion.alt.length).toBeGreaterThan(10);
    }
    // La recepción de citas no reutiliza la foto con objetos de yoga.
    expect(MODALIDADES.citas.fotos.recepcion.src).not.toBe(
      MODALIDADES.clases.fotos.recepcion.src,
    );
  });

  it("los giros del registro son los del servidor y lib/modalidad los reutiliza", () => {
    // Cada modalidad cierra con el suyo para quien no encuentra su giro.
    expect(PERFILES_POR_MODO.clases.at(-1)).toBe("general");
    expect(PERFILES_POR_MODO.citas).toEqual([
      "barberia",
      "estetica",
      "salon",
      "spa",
      "salud",
      "general_citas",
    ]);
    expect(es.registro.perfiles.general).toBe("Otro negocio con clases");
    expect(es.registro.perfiles.general_citas).toBe("Otro negocio de citas");
    expect(modoDePerfil("general")).toBe("clases");
    expect(modoDePerfil("general_citas")).toBe("citas");
    expect(GIROS_POR_MODALIDAD).toBe(PERFILES_POR_MODO);
    for (const modo of MODOS) {
      expect(girosDeNegocio(modo)).toEqual(girosDe(modo));
      for (const giro of girosDe(modo)) {
        expect(modoDeGiro(giro)).toBe(modo);
      }
    }
    expect(modoDeGiro("general")).toBe("clases");
  });

  it("los giros generales no se muestran a los clientes como insignia", () => {
    // Uno por modalidad, y cada uno cierra la lista de su modalidad.
    expect(PERFILES_GENERALES).toEqual(
      MODOS.map((modo) => PERFILES_POR_MODO[modo].at(-1)),
    );
    expect(perfilVisibleAlPublico("general")).toBe(false);
    expect(perfilVisibleAlPublico("general_citas")).toBe(false);
    for (const vacio of ["", null, undefined]) {
      expect(perfilVisibleAlPublico(vacio)).toBe(false);
    }
    for (const modo of MODOS) {
      for (const giro of PERFILES_POR_MODO[modo].slice(0, -1)) {
        expect(perfilVisibleAlPublico(giro)).toBe(true);
      }
    }
  });

  it("cada negocio del carrusel y cada página por giro tiene una sola modalidad", () => {
    const negociosLanding = Object.keys(es.landing.paraQuien.negocios);
    expect([...negociosDe("clases"), ...negociosDe("citas")].sort()).toEqual(
      [...negociosLanding].sort(),
    );
    expect(NEGOCIOS_POR_MODO.clases).toHaveLength(9);
    expect(NEGOCIOS_POR_MODO.citas).toHaveLength(8);
    expect(modoDeGiro("wellness")).toBe("citas");
    expect(modoDeGiro("hyrox")).toBe("clases");
    for (const s of soluciones) {
      expect(modoDeGiro(s.slug)).toBe(s.modo);
    }
    expect(solucionesDe("citas").every((s) => s.modo === "citas")).toBe(true);
    expect(solucionesDe("clases").length + solucionesDe("citas").length).toBe(
      soluciones.length,
    );
    // Ninguna clave aparece en las dos modalidades.
    const clases = new Set([
      ...PERFILES_POR_MODO.clases,
      ...NEGOCIOS_POR_MODO.clases,
    ]);
    for (const clave of [
      ...PERFILES_POR_MODO.citas,
      ...NEGOCIOS_POR_MODO.citas,
    ]) {
      expect(clases.has(clave), clave).toBe(false);
    }
    expect(modoDeGiro("desconocido")).toBeNull();
  });

  it("normaliza el ?giro= y solo acepta giros del registro", () => {
    expect(giroDeQuery("barberia")).toBe("barberia");
    expect(giroDeQuery(" Pilates ")).toBe("pilates");
    expect(giroDeQuery(["general_citas", "pole"])).toBe("general_citas");
    // Un negocio del carrusel o el slug de una página por giro no son giros.
    for (const invalido of [
      "wellness",
      "pole-dance",
      "talleres",
      "",
      null,
      undefined,
      3,
      [null],
    ]) {
      expect(giroDeQuery(invalido)).toBeNull();
    }
    expect(modoDePerfil("wellness")).toBeNull();
  });

  it("cada página por giro y cada negocio del carrusel apunta a un giro de su modalidad", () => {
    for (const s of soluciones) {
      const perfil = perfilDeSolucion(s.slug);
      if (perfil !== null) {
        expect(modoDePerfil(perfil), s.slug).toBe(s.modo);
      }
    }
    // Las páginas de un solo giro lo mandan; «Barberías y estéticas» junta dos.
    expect(perfilDeSolucion("pilates")).toBe("pilates");
    expect(perfilDeSolucion("pole-dance")).toBe("pole");
    expect(perfilDeSolucion("academias")).toBe("academia");
    // «CrossFit y HYROX» junta dos giros: solo la modalidad.
    expect(perfilDeSolucion("crossfit-hyrox")).toBeNull();
    expect(perfilDeSolucion("nutriologos")).toBe("salud");
    expect(perfilDeSolucion("terapeutas")).toBe("salud");
    expect(perfilDeSolucion("spas")).toBe("spa");
    expect(perfilDeSolucion("barberias")).toBeNull();
    // Ninguna clave sobrante.
    expect(
      Object.keys(PERFIL_DE_SOLUCION).every((slug) =>
        soluciones.some((s) => s.slug === slug),
      ),
    ).toBe(true);
    for (const modo of MODOS) {
      for (const negocio of negociosDe(modo)) {
        const perfil = perfilDeNegocio(negocio);
        // «Peluquerías y estéticas» junta dos giros (estética y salón): ninguno.
        if (negocio === "estetica") {
          expect(perfil).toBeNull();
          continue;
        }
        expect(perfil, negocio).not.toBeNull();
        expect(modoDePerfil(perfil!), negocio).toBe(modo);
      }
    }
    expect(es.landing.paraQuien.negocios.estetica.nombre).toBe(
      "Peluquerías y estéticas",
    );
    expect(perfilDeNegocio("wellness")).toBe("spa");
    expect(perfilDeNegocio("hyrox")).toBe("hyrox");
    expect(perfilDeNegocio("crossfit")).toBe("crossfit");
    expect(perfilDeNegocio(undefined)).toBeNull();
    // Ninguna clave sobrante.
    for (const clave of Object.keys(PERFIL_DE_NEGOCIO)) {
      expect(modoDeGiro(clave), clave).not.toBeNull();
      expect([
        ...NEGOCIOS_POR_MODO.clases,
        ...NEGOCIOS_POR_MODO.citas,
      ]).toContain(clave);
    }
  });

  it("las dos modalidades preguntan quién cambia la modalidad, sin nombrar la otra", () => {
    for (const modo of MODOS) {
      expect(MODALIDADES[modo].preguntas).toContain("modalidad");
      const { q, a } = es.landing[modo].faq.modalidad;
      // Dos productos (ADR 0108): cambiar la modalidad es pasar al otro producto, y
      // solo lo hace el equipo, antes de operar (ADR 0104).
      expect(a).toMatch(/tienen? su propio producto/);
      expect(a).toContain("solo el equipo de AgendaUno puede pasarlo al otro");
      expect(a).toContain("antes de que empieces a operar");
      expect(a).toContain("registra un negocio en cada producto");
      const otra =
        modo === "clases"
          ? /\bcitas?\b|profesional/i
          : /\bclases?\b|\bcupos?\b/i;
      expect(`${q} ${a}`).not.toMatch(otra);
      expect(`${q} ${a}`).not.toMatch(/TurnoUno/);
    }
  });

  it("los títulos de las portadas caben en 60 caracteres con la marca de su producto", () => {
    for (const modo of MODOS) {
      const marca = modo === "citas" ? "TurnoUno" : "AgendaUno";
      const titulo = MODALIDADES[modo].seo.title.replace("AgendaUno", marca);
      expect(titulo.length, titulo).toBeLessThanOrEqual(60);
      expect(MODALIDADES[modo].seo.imagenAlt.length).toBeGreaterThan(10);
    }
  });
});
