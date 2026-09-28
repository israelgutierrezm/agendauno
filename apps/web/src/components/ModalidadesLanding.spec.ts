import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import ModalidadesLanding from "./ModalidadesLanding.vue";

describe("rutas comerciales por forma de trabajo", () => {
  it("presenta clases primero y después citas, con ejemplos identificados", () => {
    const vista = mount(ModalidadesLanding);
    expect(vista.findAll("h3").map((n) => n.text())).toEqual([
      "Organizo clases",
      "Atiendo por cita",
    ]);
    expect(vista.findAll("img")).toHaveLength(2);
    expect(vista.text()).toContain("Ejemplo de agenda");
    expect(vista.text()).toContain("Pole dance");
    expect(vista.findAll(".modalidad-reserva")).toHaveLength(2);
    expect(vista.find(".modalidad-cupos").exists()).toBe(false);
    expect(vista.find(".modalidad-horarios").exists()).toBe(false);
    expect(
      vista.findAll(".modalidad-imagen img").map((n) => n.attributes("src")),
    ).toEqual([
      "/assets/landing/disciplinas/pilates-v1.jpg",
      "/assets/landing/disciplinas/barberia-v1.jpg",
    ]);
    vista.unmount();
  });
  it("abre la demo correspondiente sin enviar al visitante a otro registro", async () => {
    const vista = mount(ModalidadesLanding);
    const enlaces = vista.findAll("a");
    expect(enlaces.every((n) => n.attributes("href") === "#producto")).toBe(
      true,
    );
    await enlaces[1]!.trigger("click");
    await enlaces[0]!.trigger("click");
    expect(vista.emitted("elegir")).toEqual([["citas"], ["clases"]]);
    vista.unmount();
  });
});
