import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import EditorBloqueSitio from "@/components/EditorBloqueSitio.vue";
import { i18n } from "@/i18n";
import type { SeccionSitio } from "@/lib/sitioWeb";

/*
| El bloque de formulario del sitio: si su formulario se apagó o se borró, el bloque no
| sale en la página y el editor lo dice.
*/

vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));

const CATALOGO = {
  max_titulo: 80,
  max_texto: 600,
  max_elementos: 12,
  max_preguntas: 12,
  fondos: ["normal"],
  alineaciones: ["izquierda"],
  formularios: [{ id: "f1", nombre: "Clase muestra" }],
};

function montar(formulario: string | null) {
  return mount(EditorBloqueSitio, {
    props: {
      catalogo: CATALOGO,
      prefijo: "secciones.3",
      errores: {},
      modelValue: {
        tipo: "formulario",
        visible: true,
        datos: { formulario_id: formulario },
      } as unknown as SeccionSitio,
    },
    global: { plugins: [i18n] },
  });
}

describe("bloque de formulario del sitio", () => {
  it("avisa si su formulario está apagado o ya no existe", () => {
    expect(
      montar("f-apagado").get('[data-prueba="formulario-apagado"]').text(),
    ).toContain("el bloque no se muestra en tu página");
  });

  it("con un formulario encendido (o sin elegir) no avisa nada", () => {
    expect(
      montar("f1").find('[data-prueba="formulario-apagado"]').exists(),
    ).toBe(false);
    expect(
      montar(null).find('[data-prueba="formulario-apagado"]').exists(),
    ).toBe(false);
  });
});

describe("cómo se muestra un bloque (ADR 0167)", () => {
  it("la galería se elige como siempre, en mosaico o en carrusel", async () => {
    const w = mount(EditorBloqueSitio, {
      props: {
        catalogo: CATALOGO,
        prefijo: "secciones.3",
        errores: {},
        modelValue: {
          tipo: "galeria",
          visible: true,
          datos: { fotos: [] },
        } as unknown as SeccionSitio,
        "onUpdate:modelValue": () => undefined,
      },
      global: { plugins: [i18n] },
    });
    expect(
      w.get('[data-prueba="presentacion-siempre"]').attributes("aria-pressed"),
    ).toBe("true");
    await w.get('[data-prueba="presentacion-carrusel"]').trigger("click");
    expect(
      w.get('[data-prueba="presentacion-carrusel"]').attributes("aria-pressed"),
    ).toBe("true");
    expect(w.find('[data-prueba="presentacion-tarjetas"]').exists()).toBe(
      false,
    );
  });
});
