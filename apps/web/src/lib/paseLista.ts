import { normalizar } from "@/lib/menu";

/**
 * Pase de lista de una clase (ADR 0101): cómo va cada quien, el resumen de la lista
 * y el filtro de la pantalla. Lo mismo en la pantalla de la lista, en el detalle de
 * la clase (Recepción, Mis clases) y en la Agenda: un solo nombre para cada estado.
 */

/** Una reserva de la lista, como la devuelve GET /sesiones/{id}/reservas. */
export interface ReservaLista {
  id: string;
  estado: string;
  lugar: number | null;
  persona_id: string | null;
  persona: string | null;
  primera_vez: boolean;
  adeudo: boolean;
  documentos_pendientes: number;
  asistencia: string | null;
  retardo?: boolean;
  asistencia_automatica?: boolean;
}

/**
 * Cómo va cada quien: por marcar, llegó (a tiempo o tarde), no vino (o el sistema la
 * marcó al terminar la clase) o aún sin lugar firme (oferta de lugar o pago
 * pendiente: no se le pasa lista hasta que se confirme).
 */
export type EstadoEnLista =
  | "por_marcar"
  | "llego"
  | "tarde"
  | "no_vino"
  | "no_se_presento"
  | "sin_confirmar";

export function estadoEnLista(
  r: Pick<
    ReservaLista,
    "estado" | "asistencia" | "retardo" | "asistencia_automatica"
  >,
): EstadoEnLista {
  if (r.asistencia === "presente") {
    return r.retardo ? "tarde" : "llego";
  }
  if (r.asistencia === "ausente") {
    return r.asistencia_automatica ? "no_se_presento" : "no_vino";
  }
  return r.estado === "confirmada" ? "por_marcar" : "sin_confirmar";
}

/** El color del punto de cada estado (el texto va aparte, siempre). */
export function colorEstado(e: EstadoEnLista): string {
  switch (e) {
    case "llego":
      return "var(--exito)";
    case "tarde":
    case "sin_confirmar":
      return "var(--aviso)";
    case "no_vino":
    case "no_se_presento":
      return "var(--error)";
    default:
      return "var(--texto-suave)";
  }
}

/** Ocupan lugar: confirmadas, ofrecidas y pendientes de pago. */
export function ocupaLugar(r: Pick<ReservaLista, "estado">): boolean {
  return (
    r.estado === "confirmada" ||
    r.estado === "ofrecida" ||
    r.estado === "pendiente_pago"
  );
}

export interface ResumenLista {
  // Quienes ocupan lugar.
  enSala: number;
  llegaron: number;
  porMarcar: number;
  noVinieron: number;
  enEspera: number;
  // null = sin cupo (no se cuentan lugares).
  libres: number | null;
}

export function resumenLista(
  lista: readonly ReservaLista[],
  capacidad: number | null,
): ResumenLista {
  const enSala = lista.filter(ocupaLugar);
  const cuenta = (...estados: EstadoEnLista[]): number =>
    enSala.filter((r) => estados.includes(estadoEnLista(r))).length;
  return {
    enSala: enSala.length,
    llegaron: cuenta("llego", "tarde"),
    porMarcar: cuenta("por_marcar"),
    noVinieron: cuenta("no_vino", "no_se_presento"),
    enEspera: lista.filter((r) => r.estado === "en_espera").length,
    libres: capacidad === null ? null : Math.max(0, capacidad - enSala.length),
  };
}

export type FiltroLista = "todos" | "por_marcar" | "llegaron" | "no_vinieron";

const DEL_FILTRO: Record<FiltroLista, EstadoEnLista[] | null> = {
  todos: null,
  por_marcar: ["por_marcar", "sin_confirmar"],
  llegaron: ["llego", "tarde"],
  no_vinieron: ["no_vino", "no_se_presento"],
};

/**
 * Quienes ocupan lugar, en orden alfabético (fijo: marcar a alguien no lo cambia de
 * lugar en la lista), con el filtro y la búsqueda por nombre (sin acentos).
 */
export function filtrarLista(
  lista: readonly ReservaLista[],
  filtro: FiltroLista,
  busqueda = "",
): ReservaLista[] {
  const estados = DEL_FILTRO[filtro];
  const q = normalizar(busqueda.trim());
  return lista
    .filter(ocupaLugar)
    .filter((r) => estados === null || estados.includes(estadoEnLista(r)))
    .filter((r) => q === "" || normalizar(r.persona ?? "").includes(q))
    .sort((a, b) =>
      (a.persona ?? "").localeCompare(b.persona ?? "", "es", {
        sensitivity: "base",
      }),
    );
}
