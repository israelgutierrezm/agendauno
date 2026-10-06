import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ListaRoles from "./ListaRoles.vue";

/*
| El selector de roles habla como el negocio aunque al entrar aún rijan los textos
| base: en una barbería, «Barbero» con sus citas y sus clientes.
*/

const negocio = vi.hoisted(() => ({
  citas: true,
  miembro: "Alumno",
  genero: null as string | null,
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    get esCitas() {
      return negocio.citas;
    },
    get terminologia() {
      return negocio.citas
        ? { sesion: "Cita", miembro: "Cliente", instructor: "Barbero" }
        : {
            sesion: "Clase",
            miembro: negocio.miembro,
            instructor: "Instructor",
          };
    },
    get usuario() {
      return { genero: negocio.genero };
    },
  }),
}));

function montar() {
  return mount(ListaRoles, {
    props: {
      roles: [
        { clave: "admin", faceta: "equipo" },
        { clave: "instructor", faceta: "instructor" },
        { clave: "miembro", faceta: "miembro" },
      ],
      marcado: null,
      etiquetaMarca: "Activo",
      aplicando: null,
    },
    global: { plugins: [i18n] },
  });
}

describe("selector de roles con las palabras del negocio", () => {
  it("en una barbería: Barbero, sus citas y sus clientes", () => {
    negocio.citas = true;
    const [admin, barbero, cliente] = montar()
      .findAll("li")
      .map((li) => li.text());
    expect(admin).toContain("clientes, cobros y reportes");
    expect(barbero).toContain("Barbero");
    expect(barbero).toContain("Tus citas, tu agenda y tus clientes.");
    expect(barbero).not.toContain("Instructor");
    expect(cliente).toContain("Cliente");
    expect(cliente).toContain("Tus citas, tus pagos y tu expediente.");
  });

  it("en clases: quien imparte pasa lista a sus alumnos", () => {
    negocio.citas = false;
    const barbero = montar().findAll("li")[1].text();
    expect(barbero).toContain("Instructor");
    expect(barbero).toContain(
      "Tus clases, tu agenda y la asistencia de tus alumnos.",
    );
  });

  it("nombra los roles en el género de quien entra (su ficha)", () => {
    negocio.citas = false;
    negocio.miembro = "Alumna";
    negocio.genero = "hombre";
    const roles = mount(ListaRoles, {
      props: {
        roles: [
          { clave: "propietario", faceta: "equipo" },
          { clave: "miembro", faceta: "miembro" },
        ],
        marcado: null,
        etiquetaMarca: "Activo",
        aplicando: null,
      },
      global: { plugins: [i18n] },
    })
      .findAll(".lr-nombre")
      .map((n) => n.text());
    expect(roles).toEqual(["Dueño", "Alumno"]);
    negocio.genero = null;
    negocio.miembro = "Alumno";
  });
});
