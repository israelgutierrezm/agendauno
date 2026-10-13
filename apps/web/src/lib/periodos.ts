import { hoyEnNegocio } from "@/lib/hoyNegocio";

/**
 * Atajos de periodo de los listados (días del negocio, AAAA-MM-DD): hoy, los últimos 7
 * días, este mes y el mes pasado. `periodoDe` dice cuál es un rango, si es uno de ellos.
 */
export const PERIODOS = ["hoy", "semana", "mes", "mesPasado"] as const;
export type Periodo = (typeof PERIODOS)[number];

function moverDias(dia: string, dias: number): string {
  const fecha = new Date(`${dia}T12:00:00Z`);
  fecha.setUTCDate(fecha.getUTCDate() + dias);
  return fecha.toISOString().slice(0, 10);
}

export function rangoDePeriodo(
  periodo: Periodo,
  zona: string,
  ahora: Date = new Date(),
): { desde: string; hasta: string } {
  const hoy = hoyEnNegocio(zona, ahora);
  const inicioDeMes = `${hoy.slice(0, 8)}01`;
  switch (periodo) {
    case "hoy":
      return { desde: hoy, hasta: hoy };
    case "semana":
      return { desde: moverDias(hoy, -6), hasta: hoy };
    case "mes":
      return { desde: inicioDeMes, hasta: hoy };
    case "mesPasado": {
      const finDelPasado = moverDias(inicioDeMes, -1);
      return { desde: `${finDelPasado.slice(0, 8)}01`, hasta: finDelPasado };
    }
  }
}

export function periodoDe(
  desde: string,
  hasta: string,
  zona: string,
  ahora: Date = new Date(),
): Periodo | null {
  return (
    PERIODOS.find((p) => {
      const r = rangoDePeriodo(p, zona, ahora);
      return r.desde === desde && r.hasta === hasta;
    }) ?? null
  );
}
