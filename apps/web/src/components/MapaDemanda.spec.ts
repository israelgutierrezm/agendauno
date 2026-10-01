import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import MapaDemanda, { type CeldaDemanda } from "./MapaDemanda.vue";

function celda(
  dia: number,
  hora: number,
  confirmadas: number,
  capacidad: number,
  espera = 0,
): CeldaDemanda {
  return {
    dia,
    hora,
    sesiones: 1,
    capacidad,
    confirmadas,
    espera,
    ocupacion_pct: Math.round((confirmadas / capacidad) * 100),
  };
}

// Lunes 10:00 lleno, martes 10:00 a la mitad, lunes 19:00 con poco y espera el sábado.
const celdas = [
  celda(1, 10, 6, 6),
  celda(2, 10, 3, 6),
  celda(1, 19, 2, 6),
  celda(6, 12, 6, 6, 3),
];

function montar() {
  return mount(MapaDemanda, {
    props: { celdas, porAgenda: false, promedioPct: 71 },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: esMX },
        }),
      ],
    },
  });
}

describe("Demanda por horario", () => {
  it("resume el promedio, la hora pico y cuántos horarios se llenaron", () => {
    const w = montar();

    expect(w.get('[data-prueba="indicador-promedio"]').text()).toContain("71%");
    // A las 10: 9 de 12 lugares; a las 12: 6 de 6 (la más llena).
    expect(w.get('[data-prueba="indicador-pico"]').text()).toContain("12:00");
    expect(w.get('[data-prueba="indicador-llenos"]').text()).toContain(
      "2 de 4",
    );
  });

  it("el mapa de calor da el porcentaje de cada horario y su intensidad", () => {
    const w = montar();

    const lleno = w.get('[data-prueba="celda-1-10"]');
    expect(lleno.text()).toBe("100%");
    expect(lleno.classes()).toContain("md-alta");
    expect(w.get('[data-prueba="celda-2-10"]').classes()).toContain("md-media");
    expect(w.get('[data-prueba="celda-1-19"]').classes()).toContain("md-baja");
    // Lugares reservados al pasar el cursor.
    expect(lleno.attributes("data-detalle")).toBe("6/6");
  });

  it("con lista de espera se puede ver la espera, y las burbujas como alternativa", async () => {
    const w = montar();

    const medida = w.get('[data-prueba="medida-demanda"]');
    await medida.findAll("button")[1].trigger("click");
    expect(w.get('[data-prueba="celda-6-12"]').text()).toBe("3");
    expect(w.get('[data-prueba="celda-1-10"]').text()).toBe("0");

    await w
      .get('[data-prueba="vista-demanda"]')
      .findAll("button")[1]
      .trigger("click");
    expect(w.find('[data-prueba="burbujas"]').exists()).toBe(true);
    expect(w.findAll(".md-burbuja")).toHaveLength(4);
  });

  it("el detalle muestra el horario más alto y el que se toca", async () => {
    const w = montar();
    const detalle = () => w.get('[data-prueba="detalle-demanda"]').text();

    expect(detalle()).toContain("Lun · 10:00");
    expect(detalle()).toContain("6 de 6 lugares reservados");

    await w.get('[data-prueba="celda-6-12"]').trigger("click");
    expect(detalle()).toContain("Sáb · 12:00");
    expect(detalle()).toContain("3 en lista de espera");
  });
});
