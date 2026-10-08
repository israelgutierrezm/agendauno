import { describe, expect, it } from "vitest";

import es from "@/i18n/locales/es-MX";
import modalidadNegocio from "@/i18n/locales/modalidad.es-MX";
import { MODOS, PERFILES_POR_MODO, esModo, modoDeGiro } from "./modalidades";
import { soluciones, type Solucion } from "./soluciones";

/*
| Cada giro tiene una sola modalidad (ADR 0104) y los textos de las páginas por giro
| solo prometen lo que el producto hace hoy.
*/

function textoDe(s: Solucion): string {
  return [
    s.titulo,
    s.descripcion,
    s.encabezado,
    s.resumen,
    s.alt,
    s.ejemplo,
    ...s.beneficios.flatMap((b) => [b.titulo, b.texto]),
    ...s.preguntas.flatMap((p) => [p.pregunta, p.respuesta]),
  ].join("\n");
}

describe("giros y su modalidad", () => {
  it("cada tipo de negocio del registro es de una sola modalidad", () => {
    const perfiles = Object.keys(es.registro.perfiles);
    const conModo = MODOS.flatMap((m) => [...PERFILES_POR_MODO[m]]);
    expect([...conModo].sort()).toEqual([...perfiles].sort());
    expect(new Set(conModo).size).toBe(conModo.length);
    for (const perfil of perfiles) {
      expect(esModo(modoDeGiro(perfil)), perfil).toBe(true);
    }
  });

  it("cada modalidad tiene su giro general y su nombre lo dice", () => {
    expect(modoDeGiro("general")).toBe("clases");
    expect(es.registro.perfiles.general).toBe("Otro negocio con clases");
    expect(modoDeGiro("general_citas")).toBe("citas");
    expect(es.registro.perfiles.general_citas).toBe("Otro negocio de citas");
    // El registro solo dice que con el giro se elige la modalidad; quién la cambia
    // después va en las preguntas frecuentes.
    expect(modalidadNegocio.registro).toBe(
      "Con el tipo de negocio eliges tu modalidad, clases o citas.",
    );
    expect(es.landing.portada.faq.cambiarModalidadR).toContain(
      "Solo AgendaUno puede cambiar la modalidad",
    );
  });

  it("cada página por giro tiene la modalidad de su giro", () => {
    for (const s of soluciones) {
      expect(esModo(s.modo), s.slug).toBe(true);
      expect(modoDeGiro(s.slug), s.slug).toBe(s.modo);
    }
    for (const modo of MODOS) {
      expect(
        soluciones.some((s) => s.modo === modo),
        modo,
      ).toBe(true);
    }
  });
});

describe("textos veraces de las páginas por giro", () => {
  it("no anuncian lo que no existe", () => {
    for (const s of soluciones) {
      const texto = textoDe(s);
      // Solo hay cobro completo al agendar, no anticipos.
      expect(texto, s.slug).not.toMatch(/anticipo/i);
      expect(texto, s.slug).not.toMatch(/en preparaci[oó]n/i);
      expect(texto, s.slug).not.toMatch(/todav[ií]a no publicada/i);
      // Los recordatorios solo van por correo.
      expect(texto, s.slug).not.toMatch(/whatsapp|notificaci[oó]n push/i);
      if (/recordatorio/i.test(texto)) {
        expect(texto, s.slug).toMatch(/correo/i);
      }
    }
  });

  it("en clases no hay autorregistro ni pago por cuenta propia (ADR 0093)", () => {
    for (const s of soluciones.filter((x) => x.modo === "clases")) {
      expect(textoDe(s), s.slug).not.toMatch(
        /se registran|crean su cuenta|crear (una|su) cuenta|reg[ií]strate|pagan (en l[ií]nea|desde)/i,
      );
    }
  });

  it("los spas sí manejan cabinas y equipos en Premium y Pro (ADR 0039 y 0107)", () => {
    const spas = soluciones.find((s) => s.slug === "spas")!;
    const cabinas = spas.preguntas.find((p) => /cabinas/i.test(p.pregunta))!;
    expect(cabinas.respuesta).toMatch(/^Sí, en los planes Premium y Pro\./);
    expect(cabinas.respuesta).toContain("profesional y un espacio libres");
  });

  it("el cobro de citas es por plan y profesionales contratados (ADR 0107)", () => {
    const barberias = soluciones.find((s) => s.slug === "barberias")!;
    const cobro = barberias.preguntas.find((p) =>
      /por profesional/i.test(p.pregunta),
    )!;
    expect(cobro.respuesta).toMatch(/^Sí\./);
    expect(cobro.respuesta).toContain("Individual, Premium o Pro");
    expect(cobro.respuesta).toContain("los profesionales que contratas");
    expect(cobro.respuesta).toContain("en dólares");
  });
});
