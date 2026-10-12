import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import type { SeccionSitio } from "@/lib/sitioWeb";
import BloqueSitio from "./BloqueSitio.vue";

/*
| Bloques propios en la página pública (ADR 0146): cada tipo con su forma y su estilo;
| el video no carga nada de YouTube o Vimeo hasta que la persona le da clic; el
| formulario solo si el negocio recibe prospectos.
*/

vi.mock("@/components/FormularioCaptacion.vue", () => ({
  default: {
    props: ["slug", "formulario"],
    template: '<div data-prueba="formulario-captacion">{{ formulario }}</div>',
  },
}));

function bloque(cambios: Partial<SeccionSitio>): SeccionSitio {
  return {
    id: "b1",
    tipo: "llamado",
    titulo: null,
    texto: null,
    foto_url: null,
    estilo: { fondo: "normal", alineacion: "izquierda" },
    datos: {},
    ...cambios,
  };
}
function montar(b: SeccionSitio, captacionActiva = true) {
  return mount(BloqueSitio, {
    props: { bloque: b, slug: "fluo", captacionActiva },
    global: { plugins: [i18n] },
  });
}

describe("bloques del sitio en la página pública", () => {
  it("el video muestra una portada y carga el reproductor (sin cookies) solo al dar clic", async () => {
    const w = montar(
      bloque({
        tipo: "video",
        titulo: "Conócenos",
        datos: { proveedor: "youtube", video_id: "dQw4w9WgXcQ" },
      }),
    );
    expect(w.find("iframe").exists()).toBe(false);
    expect(w.text()).toContain(
      "Al reproducirlo, el video se carga desde YouTube.",
    );

    await w.get('[data-prueba="ver-video"]').trigger("click");
    expect(w.get('[data-prueba="reproductor"]').attributes("src")).toBe(
      "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0",
    );
  });

  it("un llamado con su color y un botón; un enlace a otra página se abre aparte", () => {
    const w = montar(
      bloque({
        titulo: "Tu primera clase",
        estilo: { fondo: "marca", alineacion: "centro" },
        datos: {
          boton_texto: "Agendar",
          boton_enlace: "https://ejemplo.mx/agenda",
        },
      }),
    );
    const seccion = w.get('[data-prueba="bloque-llamado"]');
    expect(seccion.classes()).toEqual(
      expect.arrayContaining(["bs-fondo-marca", "bs-centro"]),
    );
    const boton = w.get("a");
    expect(boton.text()).toBe("Agendar");
    expect(boton.attributes("target")).toBe("_blank");
    expect(boton.attributes("rel")).toBe("noopener noreferrer");

    const interno = montar(
      bloque({ datos: { boton_texto: "Ver", boton_enlace: "#agenda" } }),
    );
    expect(interno.get("a").attributes("target")).toBeUndefined();
  });

  it("preguntas, galería, testimonios y beneficios con su contenido", () => {
    expect(
      montar(
        bloque({
          tipo: "preguntas",
          datos: {
            items: [{ pregunta: "¿Necesito experiencia?", respuesta: "No." }],
          },
        }),
      )
        .get("details")
        .text(),
    ).toContain("¿Necesito experiencia?");
    const galeria = montar(
      bloque({
        tipo: "galeria",
        datos: { fotos: [{ url: "/storage/a.jpg", pie: "Sala" }] },
      }),
    );
    expect(galeria.get("img").attributes("src")).toBe("/storage/a.jpg");
    expect(galeria.get("figcaption").text()).toBe("Sala");
    expect(
      montar(
        bloque({
          tipo: "testimonios",
          datos: { items: [{ nombre: "Ana", texto: "Me encanta" }] },
        }),
      ).text(),
    ).toContain("«Me encanta»");
  });

  it("el formulario, solo si el negocio recibe prospectos", () => {
    const b = bloque({ tipo: "formulario", datos: { formulario_id: "f1" } });
    expect(montar(b).get('[data-prueba="formulario-captacion"]').text()).toBe(
      "f1",
    );
    expect(
      montar(b, false).find('[data-prueba="formulario-captacion"]').exists(),
    ).toBe(false);
  });
});
