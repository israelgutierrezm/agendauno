import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import TextoDestacado from "./TextoDestacado.vue";

describe("énfasis editorial", () => {
  it("conserva palabras, espacios y puntuación sin duplicar el contenido", () => {
    const texto = "Lo que necesitas para operar y crecer.";
    const vista = mount(TextoDestacado, {
      props: { texto, enfasis: "crecer" },
    });
    expect(vista.text()).toBe(texto);
    expect(vista.findAll(".tu-titulo-enfasis")).toHaveLength(1);
    expect(vista.get(".tu-titulo-enfasis").text()).toBe("crecer");
    expect(vista.find("h1, h2, h3, img").exists()).toBe(false);
    vista.unmount();
  });
  it("solo destaca la primera aparición para no saturar un titular", () => {
    const vista = mount(TextoDestacado, {
      props: { texto: "Más claridad. Más tiempo.", enfasis: "Más" },
    });
    expect(vista.text()).toBe("Más claridad. Más tiempo.");
    expect(vista.findAll(".tu-titulo-enfasis")).toHaveLength(1);
    vista.unmount();
  });
  it("mantiene el título si cambia el texto o no coincide la palabra", async () => {
    const vista = mount(TextoDestacado, {
      props: { texto: "Una agenda para tu equipo", enfasis: "tu equipo" },
    });
    await vista.setProps({ texto: "Otra forma de trabajar" });
    expect(vista.text()).toBe("Otra forma de trabajar");
    expect(vista.find(".tu-titulo-enfasis").exists()).toBe(false);
    await vista.setProps({ enfasis: "" });
    expect(vista.text()).toBe("Otra forma de trabajar");
    vista.unmount();
  });
  it("trata las etiquetas HTML como texto y no como marcado ejecutable", () => {
    const vista = mount(TextoDestacado, {
      props: { texto: "Agenda <img src=x> clara", enfasis: "<img src=x>" },
    });
    expect(vista.find("img").exists()).toBe(false);
    expect(vista.text()).toBe("Agenda <img src=x> clara");
    vista.unmount();
  });
});
