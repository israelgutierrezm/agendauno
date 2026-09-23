import { describe, expect, it } from "vitest";

import {
  carriles,
  diaIso,
  estadoCita,
  fueraDeHorario,
  iniciales,
  kpisCitas,
  kpisClases,
  minutosLocal,
  PALETA_SERVICIO,
  tonoServicio,
  type SesionAgenda,
} from "./agenda";

const ZONA = "America/Mexico_City";

function cita(
  estado: string,
  asistencia: string | null,
  extra: Partial<SesionAgenda> = {},
): SesionAgenda {
  return {
    id: "s1",
    tipo: "cita",
    oferta: "Corte",
    oferta_id: "o1",
    oferta_precio_clase: 25000,
    instructor: "Beto",
    instructor_id: "p1",
    sala: null,
    // 10:00–10:30 en CDMX (UTC-6).
    inicia_en: "2026-10-05T16:00:00Z",
    termina_en: "2026-10-05T16:30:00Z",
    zona_horaria: ZONA,
    capacidad: 1,
    ocupados: 1,
    en_espera: 0,
    estado: "programada",
    cita: { reserva_id: "r1", cliente: "Ana", estado, asistencia },
    ...extra,
  };
}

describe("agenda / fechas", () => {
  it("convierte instantes a minutos locales de la zona", () => {
    expect(minutosLocal("2026-10-05T16:00:00Z", ZONA)).toBe(600);
  });

  it("calcula el día ISO (lunes = 1, domingo = 7)", () => {
    expect(diaIso("2026-10-05")).toBe(1);
    expect(diaIso("2026-10-11")).toBe(7);
  });
});

describe("agenda / estado de la cita", () => {
  const antes = new Date("2026-10-05T15:50:00Z");
  const durante = new Date("2026-10-05T16:10:00Z");
  const despues = new Date("2026-10-05T17:00:00Z");

  it("distingue pagada de pendiente de pago", () => {
    expect(estadoCita(cita("confirmada", null), antes)).toBe("confirmada");
    expect(estadoCita(cita("pendiente_pago", null), antes)).toBe(
      "pendiente_pago",
    );
  });

  it("con asistencia: llegó, en servicio o completada según la hora", () => {
    expect(estadoCita(cita("confirmada", "presente"), antes)).toBe("llego");
    expect(estadoCita(cita("confirmada", "presente"), durante)).toBe(
      "en_servicio",
    );
    expect(estadoCita(cita("confirmada", "presente"), despues)).toBe(
      "completada",
    );
    expect(estadoCita(cita("confirmada", "ausente"), despues)).toBe(
      "no_asistio",
    );
  });

  it("una sesión cancelada o sin titular es una cita cancelada", () => {
    expect(
      estadoCita(cita("confirmada", null, { estado: "cancelada" }), antes),
    ).toBe("cancelada");
    expect(estadoCita(cita("confirmada", null, { cita: null }), antes)).toBe(
      "cancelada",
    );
  });
});

describe("agenda / colores y cupo", () => {
  it("el tono del servicio es estable por su lugar en el catálogo", () => {
    const catalogo = ["a", "b", "c"];
    expect(tonoServicio("b", catalogo)).toEqual(PALETA_SERVICIO[1]);
    expect(tonoServicio("zzz", catalogo)).toEqual(
      tonoServicio("zzz", catalogo),
    );
  });

  it("toma las iniciales de nombre y apellido", () => {
    expect(iniciales("Beto Ramírez Soto")).toBe("BR");
    expect(iniciales(null)).toBe("");
  });
});

describe("agenda / carriles y horario", () => {
  it("reparte en carriles solo lo que se solapa", () => {
    expect(
      carriles([
        { ini: 600, fin: 660 },
        { ini: 630, fin: 690 },
        { ini: 700, fin: 730 },
      ]),
    ).toEqual([
      { carril: 0, total: 2 },
      { carril: 1, total: 2 },
      { carril: 0, total: 1 },
    ]);
  });

  it("sombrea lo que queda fuera de las ventanas de atención", () => {
    expect(
      fueraDeHorario(
        [
          { ini: 600, fin: 840 },
          { ini: 900, fin: 1080 },
        ],
        540,
        1200,
      ),
    ).toEqual([
      { ini: 540, fin: 600 },
      { ini: 840, fin: 900 },
      { ini: 1080, fin: 1200 },
    ]);
    expect(fueraDeHorario([], 540, 1200)).toEqual([]);
  });
});

describe("agenda / KPIs", () => {
  it("resume las citas del día", () => {
    const ahora = new Date("2026-10-05T16:10:00Z");
    const k = kpisCitas(
      [
        cita("pendiente_pago", null),
        cita("confirmada", "presente"),
        cita("confirmada", "ausente"),
        cita("confirmada", null, { estado: "cancelada" }),
      ],
      ahora,
    );
    expect(k).toEqual({
      citas: 3,
      enLocal: 1,
      pendientesPago: 1,
      porCobrarMinor: 25000,
      noAsistieron: 1,
    });
  });

  it("cuenta por cobrar las citas agendadas por el negocio sin cobrar", () => {
    const ahora = new Date("2026-10-05T15:00:00Z");
    const porCobrar = cita("confirmada", null);
    porCobrar.cita = { ...porCobrar.cita!, orden_id: "o9", por_cobrar: true };
    const pagada = cita("confirmada", null);
    pagada.cita = { ...pagada.cita!, orden_id: "o8", por_cobrar: false };
    const k = kpisCitas([porCobrar, pagada], ahora);
    expect(k.pendientesPago).toBe(1);
    expect(k.porCobrarMinor).toBe(25000);
  });

  it("resume la ocupación de las clases", () => {
    const clase = (
      ocupados: number,
      espera: number,
      inicia: string,
    ): SesionAgenda =>
      cita("confirmada", null, {
        tipo: "clase",
        cita: null,
        capacidad: 10,
        ocupados,
        en_espera: espera,
        inicia_en: inicia,
      });
    const k = kpisClases(
      [
        clase(10, 2, "2026-10-05T14:00:00Z"),
        clase(5, 0, "2026-10-06T14:00:00Z"),
      ],
      new Date("2026-10-05T20:00:00Z"),
    );
    expect(k).toEqual({
      clases: 2,
      ocupacionPct: 75,
      reservados: 15,
      enEspera: 2,
      libresPorLlenar: 5,
    });
  });
});
