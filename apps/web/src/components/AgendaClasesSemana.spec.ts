import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import AgendaClasesSemana from "./AgendaClasesSemana.vue";

/*
| La semana de clases no afirma «Sin clases» mientras carga ni si no se pudo
| consultar: un día vacío solo se dice cuando la consulta terminó bien.
*/

function montar(extra: { cargando?: boolean; sinConsultar?: boolean } = {}) {
  return mount(AgendaClasesSemana, {
    props: {
      dias: [{ iso: "2030-01-07", nombre: "lun", dia: 7, esHoy: false }],
      sesiones: [],
      catalogo: [],
      seleccionada: null,
      ...extra,
    },
    global: { plugins: [i18n] },
  });
}

describe("semana de clases", () => {
  it("mientras carga dice que se actualiza", () => {
    const w = montar({ cargando: true });
    expect(w.text()).toContain("Actualizando agenda…");
    expect(w.text()).not.toContain("Sin clases");
  });

  it("si no se pudo consultar, lo dice", () => {
    expect(montar({ sinConsultar: true }).text()).toContain(
      "No se pudo consultar",
    );
  });

  it("ya consultada, un día vacío dice «Sin clases»", () => {
    expect(montar().text()).toContain("Sin clases");
  });
});
