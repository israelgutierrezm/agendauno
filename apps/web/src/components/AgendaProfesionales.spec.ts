import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import AgendaProfesionales from "./AgendaProfesionales.vue";

/*
| Columna sin citas: el aviso «No hay citas en este día» va en un hueco del horario,
| nunca encima de «Fuera de horario»; si ese día no atiende, no se muestra.
*/

// Jueves 10 de enero de 2030, 9:00 en CDMX.
const AHORA = new Date("2030-01-10T15:00:00Z");

function montar(ventanas: { ini: string; fin: string }[]) {
  return mount(AgendaProfesionales, {
    props: {
      fecha: "2030-01-10",
      zona: "America/Mexico_City",
      sesiones: [],
      profesionales: [{ id: "p1", nombre: "Sofía" }],
      // Otro día con horario: así el jueves sin ventanas cuenta como «no atiende».
      ventanas: [
        ...ventanas.map((v) => ({
          instructor_id: "p1",
          sucursal_id: "s1",
          dia_semana: 4,
          hora_inicio: v.ini,
          hora_fin: v.fin,
        })),
        {
          instructor_id: "p1",
          sucursal_id: "s1",
          dia_semana: 1,
          hora_inicio: "09:00",
          hora_fin: "18:00",
        },
      ],
      catalogo: [],
      seleccionada: null,
      puedeCrear: true,
    },
    global: { plugins: [i18n] },
  });
}

function rango(el: HTMLElement): { top: number; fin: number } {
  const top = parseFloat(el.style.top);
  const alto = parseFloat(el.style.height || "190");
  return { top, fin: top + alto };
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(AHORA);
});
afterEach(() => {
  vi.useRealTimers();
});

describe("columna sin citas", () => {
  it("el aviso no queda encima de «Fuera de horario»", () => {
    // Atiende de 11 a 18: de la apertura a las 11 está fuera de horario.
    const w = montar([{ ini: "11:00", fin: "18:00" }]);
    const vacio = w.get(".ag-vacio").element as HTMLElement;
    const aviso = {
      top: parseFloat(vacio.style.top),
      fin: parseFloat(vacio.style.top) + 190,
    };

    const franjas = w
      .findAll(".ag-fuera")
      .map((f) => rango(f.element as HTMLElement));
    expect(franjas.length).toBeGreaterThan(0);
    for (const f of franjas) {
      const seEncima = aviso.top < f.fin && f.top < aviso.fin;
      expect(seEncima).toBe(false);
    }
  });

  it("si ese día no atiende, no invita a agendar", () => {
    const w = montar([]);
    expect(w.find(".ag-vacio").exists()).toBe(false);
    expect(w.text()).toContain("No atiende este día");
  });
});
