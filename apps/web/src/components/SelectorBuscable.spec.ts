import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { defineComponent, ref } from "vue";

import { i18n } from "@/i18n";
import { filtrarOpciones, type OpcionBuscable } from "@/lib/buscable";
import SelectorBuscable from "./SelectorBuscable.vue";

/*
| Una lista larga con buscador (países, zonas horarias): se ve como un campo con lo
| elegido; al entrar muestra todo agrupado y al escribir filtra, sin acentos.
*/

const OPCIONES: OpcionBuscable[] = [
  { valor: "MX", etiqueta: "México", detalle: "+52", grupo: "Más usados" },
  { valor: "CO", etiqueta: "Colombia", detalle: "+57", grupo: "Más usados" },
  { valor: "BR", etiqueta: "Brasil", detalle: "+55", grupo: "Todos" },
  { valor: "PE", etiqueta: "Perú", detalle: "+51", grupo: "Todos" },
];

function montar(inicial = "MX") {
  const Envoltura = defineComponent({
    components: { SelectorBuscable },
    setup() {
      return { valor: ref(inicial), opciones: OPCIONES };
    },
    template: `<label for="sb">País</label>
      <SelectorBuscable id="sb" v-model="valor" :opciones="opciones" data-prueba="campo" />
      <span data-prueba="valor">{{ valor }}</span>`,
  });
  return mount(Envoltura, {
    attachTo: document.body,
    global: { plugins: [i18n] },
  });
}

describe("selector con buscador", () => {
  it("muestra lo elegido y, al tocarlo, toda la lista por grupos", async () => {
    const w = montar();
    const campo = w.get('[data-prueba="campo"]');
    expect(campo.attributes("id")).toBe("sb");
    expect((campo.element as HTMLInputElement).value).toBe("México");
    expect(w.find('[role="listbox"]').exists()).toBe(false);
    // Pasar por el campo (con Tab) no abre la lista; la flecha abajo, sí.
    await campo.trigger("focus");
    expect(w.find('[role="listbox"]').exists()).toBe(false);
    await campo.trigger("keydown", { key: "ArrowDown" });
    expect(w.find('[role="listbox"]').exists()).toBe(true);
    await campo.trigger("keydown", { key: "Escape" });

    await campo.trigger("click");
    expect(campo.attributes("aria-expanded")).toBe("true");
    expect(w.findAll('[role="option"]')).toHaveLength(4);
    expect(w.findAll(".sb-grupo").map((g) => g.text())).toEqual([
      "Más usados",
      "Todos",
    ]);
    // Se abre en la elegida.
    expect(w.get('[aria-selected="true"]').text()).toContain("México");
    w.unmount();
  });

  it("filtra al escribir (sin acentos) y elige con Enter", async () => {
    const w = montar();
    const campo = w.get('[data-prueba="campo"]');
    await campo.trigger("click");
    await campo.setValue("peru");
    const opciones = w.findAll('[role="option"]');
    expect(opciones.map((o) => o.text())).toEqual(["Perú+51"]);

    await campo.trigger("keydown", { key: "Enter" });
    expect(w.get('[data-prueba="valor"]').text()).toBe("PE");
    expect(w.find('[role="listbox"]').exists()).toBe(false);
    expect((campo.element as HTMLInputElement).value).toBe("Perú");
    w.unmount();
  });

  it("elige con las flechas o con el ratón, y Esc cierra sin cambiar", async () => {
    const w = montar();
    const campo = w.get('[data-prueba="campo"]');
    await campo.trigger("click");
    await campo.trigger("keydown", { key: "ArrowDown" });
    await campo.trigger("keydown", { key: "Enter" });
    expect(w.get('[data-prueba="valor"]').text()).toBe("CO");

    await campo.trigger("click");
    await w.get('[data-valor="BR"]').trigger("mousedown");
    expect(w.get('[data-prueba="valor"]').text()).toBe("BR");

    await campo.trigger("click");
    await campo.setValue("zzz");
    expect(w.text()).toContain("Nada coincide con esa búsqueda.");
    await campo.trigger("keydown", { key: "Escape" });
    expect(w.find('[role="listbox"]').exists()).toBe(false);
    expect(w.get('[data-prueba="valor"]').text()).toBe("BR");
    w.unmount();
  });

  it("al buscar, una opción repetida en dos grupos sale una vez", () => {
    const repetidas = [
      ...OPCIONES,
      { valor: "MX", etiqueta: "México", grupo: "Todos" },
    ];
    expect(filtrarOpciones(repetidas, "mex").map((o) => o.valor)).toEqual([
      "MX",
    ]);
    expect(filtrarOpciones(repetidas, "")).toHaveLength(5);
    // También por lo que no se ve (el código o el detalle).
    expect(filtrarOpciones(OPCIONES, "+57").map((o) => o.valor)).toEqual([
      "CO",
    ]);
    expect(filtrarOpciones(OPCIONES, "br").map((o) => o.valor)).toEqual(["BR"]);
  });
});
