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
});
