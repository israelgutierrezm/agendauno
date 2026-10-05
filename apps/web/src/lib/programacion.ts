/**
 * Programación recurrente (series de clases que se repiten): la semana tipo que
 * generan, para verla de lunes a domingo y filtrarla por actividad, instructor o sede.
 */
export interface SerieProgramada {
  id: string;
  oferta: string | null;
  actividad: string | null;
  actividad_id: string | null;
  sucursal: string | null;
  instructor: string | null;
  instructor_id: string | null;
  /** 1 = lunes … 7 = domingo. */
  dias_semana: number[];
  hora_local: string;
  duracion_minutos: number;
  capacidad: number | null;
  activo: boolean;
  vigente_desde: string;
  vigente_hasta: string | null;
}

export interface FiltroProgramacion {
  actividad: string;
  instructor: string;
  sucursal: string;
}

/** Valor del filtro de instructor para las series sin nadie asignado. */
export const SIN_INSTRUCTOR = "sin-asignar";

/** Sigue generando fechas: activa y sin terminar antes de `hoy` (AAAA-MM-DD). */
export function sigueVigente(s: SerieProgramada, hoy: string): boolean {
  return s.activo && (s.vigente_hasta === null || s.vigente_hasta >= hoy);
}

export function filtrarSeries(
  series: SerieProgramada[],
  f: FiltroProgramacion,
): SerieProgramada[] {
  return series.filter(
    (s) =>
      (f.actividad === "" || s.actividad_id === f.actividad) &&
      (f.instructor === "" ||
        (f.instructor === SIN_INSTRUCTOR
          ? s.instructor_id === null
          : s.instructor_id === f.instructor)) &&
      (f.sucursal === "" || s.sucursal === f.sucursal),
  );
}

/** Las siete columnas de la semana (lunes a domingo), cada una por hora. */
export function semanaDe(
  series: SerieProgramada[],
): { dia: number; clases: SerieProgramada[] }[] {
  return [1, 2, 3, 4, 5, 6, 7].map((dia) => ({
    dia,
    clases: series
      .filter((s) => s.dias_semana.includes(dia))
      .sort(
        (a, b) =>
          a.hora_local.localeCompare(b.hora_local) ||
          (a.oferta ?? "").localeCompare(b.oferta ?? ""),
      ),
  }));
}

/** Hora de término «HH:MM» de una clase que empieza a `hora` y dura `minutos`. */
export function horaFin(hora: string, minutos: number): string {
  const [h, m] = hora.split(":").map(Number);
  const total = (((h * 60 + m + minutos) % 1440) + 1440) % 1440;
  const p = (n: number) => String(n).padStart(2, "0");
  return `${p(Math.floor(total / 60))}:${p(total % 60)}`;
}

/** Nombre del día de la semana (1 = lunes) en el idioma de la interfaz. */
export function nombreDia(dia: number, largo = true): string {
  // 2024-01-01 fue lunes.
  return new Intl.DateTimeFormat("es-MX", {
    weekday: largo ? "long" : "short",
  }).format(new Date(2024, 0, dia));
}
