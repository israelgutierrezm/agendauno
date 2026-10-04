import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { i18n } from "@/i18n";
import AccesosOperativos from "./AccesosOperativos.vue";

const sesion = vi.hoisted(() => ({
  modalidad: "clases",
  terminologia: { miembro: "Alumno", instructor: "Instructor" },
  puede: (() => true) as (permiso: string) => boolean,
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
function montar() {
  return mount(AccesosOperativos, {
    global: {
      plugins: [i18n],
      stubs: { RouterLink: { props: ["to"], template: "<a><slot /></a>" } },
    },
  });
}

describe("accesos del panel", () => {
  it("ofrece recepción y alumnos para clases", () => {
    sesion.modalidad = "clases";
    sesion.puede = () => true;
    const vista = montar();
    expect(vista.text()).toContain("Recepción");
    expect(vista.text()).toContain("Alumnos");
    expect(vista.text()).not.toContain("Horarios de atención");
    vista.unmount();
  });
  it("ofrece disponibilidad y clientes para citas", () => {
    sesion.modalidad = "citas";
    sesion.terminologia.miembro = "Cliente";
    sesion.puede = () => true;
    const vista = montar();
    expect(vista.text()).toContain("Horarios de atención");
    expect(vista.text()).toContain("Clientes");
    expect(vista.text()).not.toContain("Recepción");
    // Se cobra cada cita: «Cobrar», no vender planes.
    expect(vista.text()).toContain("Cobrar");
    expect(vista.text()).not.toContain("Vender a un");
    vista.unmount();
  });
  it("no muestra enlaces sin permisos", () => {
    sesion.modalidad = "clases";
    sesion.puede = (permiso) => permiso === "agenda.ver";
    const vista = montar();
    expect(vista.findAll("a")).toHaveLength(1);
    expect(vista.text()).toContain("Agenda");
    expect(vista.text()).not.toContain("Ventas");
    vista.unmount();
  });
});
