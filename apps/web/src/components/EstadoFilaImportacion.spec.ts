import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import { fijarTerminosActuales } from "@/lib/terminologia";
import EstadoFilaImportacion from "./EstadoFilaImportacion.vue";

/*
| Los motivos de una fila importada los da la API con «alumno» fijo: se dicen con los
| términos del negocio, como sus demás mensajes (p. ej. Importar reservas).
*/

afterEach(() => fijarTerminosActuales(null));

function montar(errores: string[], estado = "invalida") {
  return mount(EstadoFilaImportacion, {
    props: { estado, errores },
    global: { plugins: [i18n] },
  });
}

describe("estado de una fila importada", () => {
  it("los motivos dicen el término del negocio, no «alumno» fijo", () => {
    fijarTerminosActuales({
      sesion: "Clase",
      miembro: "Socio",
      instructor: "Coach",
    });
    const w = montar(["No encontramos al alumno con ese correo."]);

    expect(w.text()).toContain("No encontramos al socio con ese correo.");
    expect(w.text()).not.toContain("alumno");
  });

  it("sin terminología del negocio, el motivo queda tal cual", () => {
    const w = montar(["No encontramos al alumno con ese correo."]);
    expect(w.text()).toContain("No encontramos al alumno con ese correo.");
  });
});
