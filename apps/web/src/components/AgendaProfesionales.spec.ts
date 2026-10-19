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

describe("tarjetas de cita", () => {
  // Cita de 30 min a las 10:00 (tarjeta chica), agendada por el negocio sin cobrar.
  function conCita() {
    return mount(AgendaProfesionales, {
      props: {
        fecha: "2030-01-10",
        zona: "America/Mexico_City",
        sesiones: [
          {
            id: "c1",
            tipo: "cita",
            oferta: "Corte",
            oferta_id: "o1",
            instructor: "Sofía",
            instructor_id: "p1",
            sala: null,
            inicia_en: "2030-01-10T16:00:00Z",
            termina_en: "2030-01-10T16:30:00Z",
            zona_horaria: "America/Mexico_City",
            capacidad: 1,
            ocupados: 1,
            en_espera: 0,
            estado: "programada",
            cita: {
              reserva_id: "r1",
              cliente: "Ana",
              estado: "confirmada",
              asistencia: null,
              orden_id: "o1",
              por_cobrar: true,
            },
          },
        ],
        profesionales: [{ id: "p1", nombre: "Sofía" }],
        ventanas: [],
        catalogo: [],
        seleccionada: null,
        puedeCrear: true,
        simboloCobro: "$",
      },
      global: { plugins: [i18n] },
    });
  }

  it("una tarjeta chica dice en texto cómo va y que falta cobrarla", () => {
    const tarjeta = conCita().get(".ag-tarjeta");
    // El punto de color no basta: el estado y el pago van en su título y su etiqueta.
    expect(tarjeta.attributes("title")).toContain("Agendada");
    expect(tarjeta.attributes("title")).toContain("Por cobrar");
    expect(tarjeta.attributes("aria-label")).toContain("Por cobrar");
    expect(tarjeta.find('[data-prueba="tarjeta-por-cobrar"]').text()).toBe("$");
  });
});
