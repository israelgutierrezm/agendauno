import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import SemanaHorario from "./SemanaHorario.vue";

/*
| La semana de un vistazo: franjas de atención, el descanso entre ellas y los días
| sin horario como día de descanso. Tocar un día lo edita.
*/

function montar(puedeGestionar = true) {
  return mount(SemanaHorario, {
    props: {
      semana: {
        1: [
          { hora_inicio: "09:00", hora_fin: "14:00" },
          { hora_inicio: "16:00", hora_fin: "20:00" },
        ],
        2: [],
        3: [],
        4: [],
        5: [],
        6: [],
        7: [],
      },
      puedeGestionar,
    },
    global: { plugins: [i18n] },
  });
}

describe("semana de horario", () => {
  it("muestra la atención, el descanso entre franjas y los días sin horario", () => {
    const w = montar();
    const atencion = w.findAll(".sh-atencion").map((b) => b.text());
    expect(atencion).toEqual([
      expect.stringContaining("09:00 – 14:00"),
      expect.stringContaining("16:00 – 20:00"),
    ]);
    expect(w.get(".sh-pausa").text()).toContain("14:00 – 16:00");
    expect(w.findAll(".sh-libre")).toHaveLength(6);
  });

  it("tocar un día lo edita; sin permiso, no", async () => {
    const w = montar();
    await w.findAll(".sh-libre")[0]!.trigger("click");
    expect(w.emitted("editar")).toEqual([[2]]);

    const soloVer = montar(false);
    expect(soloVer.get(".sh-atencion").attributes("disabled")).toBeDefined();
  });
});
