import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import ProductoDemo from "./ProductoDemo.vue";

describe("demo de agenda", () => {
  it("acepta la modalidad elegida desde la landing y reinicia el detalle", async () => {
    const vista = mount(ProductoDemo, { props: { modelValue: "clases" } });
    await vista.findAll(".demo-evento")[3]!.trigger("click");
    await vista.setProps({ modelValue: "citas" });
    expect(vista.get(".demo-detalle h4").text()).toBe("Corte de cabello");
    expect(vista.get(".demo-profesional").text()).toContain("Marco");
    await vista.setProps({ modelValue: "clases" });
    expect(vista.get(".demo-detalle h4").text()).toBe("Pilates Reformer");
    vista.unmount();
  });
  it("muestra datos de ejemplo y permite consultar una clase", async () => {
    const vista = mount(ProductoDemo);
    expect(vista.text()).toContain("Datos de ejemplo");
    expect(vista.get(".demo-detalle h4").text()).toBe("Pilates Reformer");
    await vista.findAll(".demo-evento")[1]!.trigger("click");
    expect(vista.get(".demo-detalle h4").text()).toBe("Pole dance básico");
    expect(vista.get(".demo-estado").text()).toBe("Clase completa");
    vista.unmount();
  });
  it("cambia a citas por profesional y reinicia la selección", async () => {
    const vista = mount(ProductoDemo);
    await vista.findAll(".demo-evento")[2]!.trigger("click");
    await vista.findAll(".demo-modos button")[1]!.trigger("click");
    expect(vista.get(".demo-detalle h4").text()).toBe("Corte de cabello");
    expect(vista.get(".demo-profesional").text()).toContain("Marco");
    expect(vista.findAll(".demo-persona").map((n) => n.text())).toEqual([
      "MMarco",
      "LLuis",
      "AAlex",
    ]);
    expect(vista.findAll('.demo-evento[aria-pressed="true"]')).toHaveLength(1);
    vista.unmount();
  });
  it("con modo clases muestra la semana con cupos, sin selector ni columnas por instructor", async () => {
    const vista = mount(ProductoDemo, { props: { modo: "clases" } });
    expect(vista.find(".demo-modos").exists()).toBe(false);
    expect(vista.find(".demo-persona").exists()).toBe(false);
    expect(vista.findAll(".demo-columna").map((n) => n.text())).toEqual([
      "Lun",
      "Mar",
      "Mié",
      "Jue",
      "Vie",
    ]);
    expect(vista.get(".demo-dia").text()).toContain("Vista de semana");
    expect(vista.get(".demo-cabecera h3").text()).toBe(
      "Cada clase con su cupo.",
    );
    expect(vista.findAll(".demo-evento")[0]!.text()).toContain(
      "6 de 8 lugares",
    );
    expect(vista.get(".demo-profesional").text()).toBe(
      "Lunes 09:00 · con Andrea",
    );
    await vista.findAll(".demo-evento")[1]!.trigger("click");
    expect(vista.get(".demo-detalle h4").text()).toBe("Pole dance básico");
    expect(vista.get(".demo-estado").text()).toBe(
      "Clase llena · 2 en lista de espera",
    );
    expect(vista.get(".demo-estado").classes()).toContain("atencion");
    // Sin íconos teñidos ni símbolos sueltos en el modo fijo.
    expect(vista.find(".demo-detalle-icono").exists()).toBe(false);
    expect(vista.get(".demo-detalle-pie").text()).not.toContain("◷");
    vista.unmount();
  });
  it("con modo citas queda fija en la agenda por profesional", async () => {
    const vista = mount(ProductoDemo, { props: { modo: "citas" } });
    expect(vista.find(".demo-modos").exists()).toBe(false);
    expect(vista.find(".demo-columna").exists()).toBe(false);
    expect(vista.findAll(".demo-persona").map((n) => n.text())).toEqual([
      "MMarco",
      "LLuis",
      "AAlex",
    ]);
    expect(vista.get(".demo-detalle h4").text()).toBe("Corte de cabello");
    expect(vista.get(".demo-estado").text()).toBe("Confirmada");
    expect(vista.get(".demo-estado").classes()).toContain("demo-estado-punto");
    expect(vista.get(".demo-estado").classes()).not.toContain("atencion");
    expect(vista.text()).not.toMatch(/cupo|lugares/i);
    await vista.findAll(".demo-evento")[1]!.trigger("click");
    expect(vista.get(".demo-profesional").text()).toBe("10:00 · Luis");
    vista.unmount();
  });
  it("sin modo conserva el selector, la vista de día y el estado en caja", () => {
    const vista = mount(ProductoDemo);
    expect(vista.findAll(".demo-modos button")).toHaveLength(2);
    expect(vista.find(".demo-columna").exists()).toBe(false);
    expect(vista.findAll(".demo-persona")).toHaveLength(3);
    expect(vista.get(".demo-estado").classes()).not.toContain(
      "demo-estado-punto",
    );
    vista.unmount();
  });
});
