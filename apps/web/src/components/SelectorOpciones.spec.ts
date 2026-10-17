import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import SelectorOpciones from "./SelectorOpciones.vue";

/*
| Elegir varias de una lista que crece con el catálogo: en filas por grupo, con
| búsqueda, conteo y «elegir todas»; el modelo guarda el orden en que se marcaron.
*/

const OPCIONES = [
  ...["Pole 1", "Pole 2", "Pole Exótico"].map((nombre, i) => ({
    id: `p${i}`,
    nombre,
    grupo: "Pole",
  })),
  ...["Yoga Flow", "Yoga Restaurativo", "Pilates Mat", "Pilates Reformer"].map(
    (nombre, i) => ({ id: `y${i}`, nombre, grupo: "Bienestar" }),
  ),
  { id: "x", nombre: "Telas aéreas", grupo: "Aéreo" },
  { id: "z", nombre: "Lyra", grupo: "Aéreo" },
];

function montar(modelValue: string[] = []) {
  return mount(SelectorOpciones, {
    props: {
      opciones: OPCIONES,
      modelValue,
      "onUpdate:modelValue": (ids: string[]) => w.setProps({ modelValue: ids }),
    },
    global: { plugins: [i18n] },
  });
}
let w: ReturnType<typeof montar>;

describe("selector de opciones", () => {
  it("por grupos, con búsqueda sin acentos y el conteo de elegidas", async () => {
    w = montar(["p1"]);
    expect(w.findAll(".so-grupo-titulo").map((g) => g.text())).toEqual([
      expect.stringContaining("Pole"),
      expect.stringContaining("Bienestar"),
      expect.stringContaining("Aéreo"),
    ]);
    expect(w.get('[data-prueba="selector-cuenta"]').text()).toBe(
      "1 de 9 elegidas",
    );

    await w.get('[data-prueba="selector-buscar"]').setValue("aereas");
    expect(w.findAll(".so-opcion").map((o) => o.text())).toEqual([
      "Telas aéreas",
    ]);
  });

  it("elegir todas las de un grupo y quitarlas, guardando el orden", async () => {
    w = montar(["x"]);
    const pole = w.findAll(".so-grupo")[0]!;
    await pole.get(".so-todas").trigger("click");
    expect(w.props("modelValue")).toEqual(["x", "p0", "p1", "p2"]);

    await w.findAll(".so-grupo")[0]!.get(".so-todas").trigger("click");
    expect(w.props("modelValue")).toEqual(["x"]);

    await w.get('input[value="y2"]').setValue(true);
    expect(w.props("modelValue")).toEqual(["x", "y2"]);
  });
});
