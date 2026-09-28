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
    const clara = vista.get(".agendauno-logo__imagen--clara");
    expect(clara.attributes("alt")).toBe("Agenda Uno");
    expect(clara.attributes("src")).toBe(
      "/assets/brand/agendauno/final-v2/logo.png",
    );
    expect(vista.attributes("style")).toContain("192px");
    vista.unmount();
  });
  it("trae la versión blanca del logo horizontal para el modo oscuro", () => {
    const vista = mount(LogoAgendaUno, { props: { variante: "horizontal" } });
    expect(vista.classes()).toContain("agendauno-logo--adaptable");
    expect(vista.get(".agendauno-logo__imagen--oscura").attributes("src")).toBe(
      "/assets/brand/agendauno/final-v2/logo-blanco.png",
    );
    vista.unmount();
  });
  it("sin adaptar (o en otras variantes) muestra una sola imagen", () => {
    const fija = mount(LogoAgendaUno, { props: { adaptable: false } });
    expect(fija.findAll("img")).toHaveLength(1);
    fija.unmount();
    const eslogan = mount(LogoAgendaUno, {
      props: { variante: "horizontal-slogan" },
    });
    expect(eslogan.findAll("img")).toHaveLength(1);
    eslogan.unmount();
  });
});
