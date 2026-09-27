import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import LogoAgendaUno from "./LogoAgendaUno.vue";

describe("logo institucional actualizado", () => {
  it("usa el isotipo original sin una placa blanca de fondo", () => {
    const vista = mount(LogoAgendaUno, {
      props: { variante: "isotipo", ancho: 64 },
    });
    expect(vista.classes()).toContain("agendauno-logo--isotipo");
    expect(vista.get("img").attributes("src")).toBe(
      "/assets/brand/agendauno/final-v2/isotipo.png",
    );
    vista.unmount();
  });
  it("usa el recurso final sin redibujarlo ni recortarlo", () => {
    const vista = mount(LogoAgendaUno, { props: { ancho: 192 } });
    expect(vista.get("img").attributes("alt")).toBe("Agenda Uno");
    expect(vista.get("img").attributes("src")).toBe(
      "/assets/brand/agendauno/final-v2/logo.png",
    );
    expect(vista.attributes("style")).toContain("192px");
    expect(vista.findAll("img")).toHaveLength(1);
    vista.unmount();
  });
});
