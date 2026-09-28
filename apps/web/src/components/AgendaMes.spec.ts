import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";

import agendaVisual from "@/i18n/locales/agendaVisual.es-MX";
import type { SesionAgenda } from "@/lib/agenda";
import AgendaMes from "./AgendaMes.vue";

function sesion(
  id: string,
  iniciaEn: string,
  oferta = "Nivel 1",
): SesionAgenda {
  return {
    id,
    tipo: "clase",
    oferta,
    oferta_id: "o1",
    oferta_precio_clase: null,
    instructor: "Beto",
    instructor_id: "i1",
    sala: null,
    inicia_en: iniciaEn,
    termina_en: iniciaEn,
    zona_horaria: "America/Mexico_City",
    capacidad: 10,
    ocupados: 0,
    en_espera: 0,
    estado: "programada",
  } as SesionAgenda;
}

function montar(sesiones: SesionAgenda[]) {
  return mount(AgendaMes, {
    props: { mes: new Date(2026, 9, 1), sesiones, catalogo: ["o1"] },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { agendaVisual } },
        }),
      ],
    },
  });
}

describe("agenda del mes", () => {
  it("arma las semanas completas del mes, de lunes a domingo", () => {
    const w = montar([]);
    // Octubre de 2026 empieza en jueves y termina en sábado: 5 semanas.
    expect(w.findAll(".am-dia")).toHaveLength(35);
    expect(w.findAll(".am-dia")[0].text()).toBe("28");
    expect(w.findAll(".am-dia")[3].text()).toBe("1");
  });

  it("lista las primeras del día, dice cuántas más hay y lleva al día", async () => {
    const w = montar([
      sesion("a", "2026-10-05T14:00:00Z"),
      sesion("b", "2026-10-05T16:00:00Z", "Nivel 2"),
      sesion("c", "2026-10-05T18:00:00Z"),
      sesion("d", "2026-10-05T23:30:00Z"),
      // 6 oct 00:30 UTC es el 5 a las 18:30 en CDMX.
      sesion("e", "2026-10-06T00:30:00Z"),
    ]);
    const dia5 = w.findAll(".am-dia")[7];
    expect(dia5.findAll(".am-evento")).toHaveLength(3);
    expect(dia5.text()).toContain("08:00");
    expect(dia5.text()).toContain("Nivel 2");
    expect(dia5.text()).toContain("+2 más");

    await dia5.find(".am-evento").trigger("click");
    expect(w.emitted("abrir")?.[0]?.[0]).toMatchObject({ id: "a" });
    await dia5.findAll("button").at(-1)!.trigger("click");
    expect(w.emitted("dia")?.[0]).toEqual(["2026-10-05"]);
  });
});
