import { describe, expect, it } from "vitest";

import {
  colorDeContraste,
  direccionDe,
  direccionLibre,
  duplicarBloque,
  esBloque,
  esEnlaceDeVideo,
  moverSeccion,
  nuevaPagina,
  nuevoBloque,
  sitioPorDefecto,
  urlReproductor,
  type TipoSeccion,
} from "./sitioWeb";

/*
| El sitio de cada negocio (ADR 0114): la portada y el contacto no se mueven; un negocio
| de citas no tiene horario de clases; el texto de los botones se lee sobre su color.
*/

const lista = (tipos: TipoSeccion[]) => tipos.map((tipo) => ({ tipo }));

describe("sitio del negocio", () => {
  it("mueve una sección sin pasar de la portada ni del contacto", () => {
    const secciones = lista(["inicio", "nosotros", "precios", "contacto"]);
    expect(moverSeccion(secciones, 1, 1).map((s) => s.tipo)).toEqual([
      "inicio",
      "precios",
      "nosotros",
      "contacto",
    ]);
    // No sube por encima de la portada ni baja después del contacto.
    expect(moverSeccion(secciones, 1, -1)).toEqual(secciones);
    expect(moverSeccion(secciones, 2, 1)).toEqual(secciones);
    // Las fijas no se mueven.
    expect(moverSeccion(secciones, 0, 1)).toEqual(secciones);
    // Devuelve otra lista (no cambia la original).
    expect(moverSeccion(secciones, 1, 1)).not.toBe(secciones);
  });

  it("la página de siempre: un negocio de citas no tiene horario de clases", () => {
    expect(sitioPorDefecto(false).secciones.map((s) => s.tipo)).toContain(
      "horario",
    );
    const citas = sitioPorDefecto(true).secciones.map((s) => s.tipo);
    expect(citas).not.toContain("horario");
    expect(citas[0]).toBe("inicio");
    expect(citas.at(-1)).toBe("contacto");
  });

  it("el texto sobre el color de la marca se lee", () => {
    expect(colorDeContraste("#031b4e")).toBe("#ffffff");
    expect(colorDeContraste("#f5d76e")).toBe("#111111");
    expect(colorDeContraste("no-es-color")).toBe("#ffffff");
  });
});

describe("bloques propios (ADR 0146)", () => {
  it("un bloque nuevo es visible, con el estilo de siempre y un id que la API conserva", () => {
    const b = nuevoBloque("preguntas");
    expect(b).toMatchObject({
      tipo: "preguntas",
      visible: true,
      estilo: { fondo: "normal", alineacion: "izquierda" },
      datos: { items: [{ pregunta: null, respuesta: null }] },
    });
    expect(b.id).toMatch(/^[a-z0-9]{20}$/);
    expect(esBloque("preguntas")).toBe(true);
    expect(esBloque("equipo")).toBe(false);
  });

  it("duplicar da otro id y no comparte lo de adentro", () => {
    const original = nuevoBloque("galeria");
    original.datos!.fotos!.push({ url: "/storage/a.jpg", pie: null });
    const copia = duplicarBloque(original);
    expect(copia.id).not.toBe(original.id);
    copia.datos!.fotos!.push({ url: "/storage/b.jpg", pie: null });
    expect(original.datos!.fotos).toHaveLength(1);
  });

  it("el reproductor sale de su proveedor y su id: YouTube sin cookies o Vimeo sin rastreo", () => {
    expect(urlReproductor("youtube", "dQw4w9WgXcQ")).toBe(
      "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0",
    );
    expect(urlReproductor("vimeo", "76979871")).toBe(
      "https://player.vimeo.com/video/76979871?autoplay=1&dnt=1",
    );
    expect(urlReproductor("youtube", 'x"><script>')).toBeNull();
    expect(urlReproductor("otro", "dQw4w9WgXcQ")).toBeNull();
    expect(esEnlaceDeVideo("https://youtu.be/dQw4w9WgXcQ")).toBe(true);
    expect(esEnlaceDeVideo("https://evil.com/video.mp4")).toBe(false);
  });
});

describe("páginas propias (ADR 0146, fase 2)", () => {
  it("la dirección sale del título: minúsculas, sin acentos y con guiones", () => {
    expect(direccionDe("Preguntas Frecuentes")).toBe("preguntas-frecuentes");
    expect(direccionDe("  Año nuevo: ¡Promoción!  ")).toBe(
      "ano-nuevo-promocion",
    );
    expect(direccionDe("Nosotros", 3)).toBe("nos");
    expect(direccionLibre("precios", ["precios", "precios-2"])).toBe(
      "precios-3",
    );
  });

  it("una página nueva va en el menú, vacía y con una dirección libre", () => {
    expect(nuevaPagina("Precios", ["precios"])).toMatchObject({
      slug: "precios-2",
      titulo: "Precios",
      en_menu: true,
      secciones: [],
      seo: { titulo: null, descripcion: null, imagen_url: null },
    });
  });
});
