/**
 * Lo que trae cada persona en el listado de Miembros con `?resumen=1`: su membresía o
 * paquete (misma regla que su resumen de Recepción), su última visita, su próxima
 * reserva y si debe.
 */
type Traducir = (
  clave: string,
  valores?: Record<string, unknown>,
  plural?: number,
) => string;

export interface ResumenTarjeta {
  membresia: {
    estado: "sin" | "vigente" | "por_vencer" | "pausada" | "vencida";
    plan: string | null;
    valido_hasta: string | null;
    pausada_hasta: string | null;
    ilimitado: boolean;
    saldo_unidades: number;
    tiene_acceso: boolean;
    // Todos los planes que hoy le dan acceso (puede tener más de uno).
    planes?: string[];
  };
  ultima_visita: string | null;
  // Su última clase o cita (ya pasó y no la canceló): cuándo y cuál.
  ultima?: {
    inicia_en: string;
    zona_horaria: string | null;
    clase: string | null;
  } | null;
  proxima: {
    inicia_en: string;
    zona_horaria: string | null;
    clase: string | null;
  } | null;
  adeudo: boolean;
}

/** "2026-10-07" → "7 oct" (fecha de calendario, sin zona). */
export function diaCorto(ymd: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
  }).format(new Date(`${ymd}T12:00:00`));
}

/** Lo que le queda: "Ilimitado", "5 créditos" o null (sin plan con acceso). */
export function creditosDe(
  m: ResumenTarjeta["membresia"] | undefined,
  t: Traducir,
): string | null {
  if (!m || !m.tiene_acceso) {
    return null;
  }
  if (m.ilimitado) {
    return t("tarjetas.ilimitado");
  }
  const n = m.saldo_unidades / 1000;
  return t(
    "tarjetas.creditos",
    {
      n: new Intl.NumberFormat("es-MX", { maximumFractionDigits: 1 }).format(n),
    },
    n === 1 ? 1 : 2,
  );
}

/** Qué pasa con su membresía, en una frase; con color solo si pide atención. */
export function estadoMembresia(
  m: ResumenTarjeta["membresia"] | undefined,
  t: Traducir,
): { texto: string; color?: string } | null {
  if (!m) {
    return null;
  }
  switch (m.estado) {
    case "vigente":
      return m.valido_hasta
        ? { texto: t("tarjetas.vence", { fecha: diaCorto(m.valido_hasta) }) }
        : null;
    case "por_vencer":
      return {
        texto: t("tarjetas.vence", { fecha: diaCorto(m.valido_hasta ?? "") }),
        color: "var(--aviso)",
      };
    case "vencida":
      return {
        texto: t("tarjetas.vencio", {
          fecha: diaCorto(m.valido_hasta ?? ""),
        }),
        color: "var(--error)",
      };
    case "pausada":
      return {
        texto: t("tarjetas.enPausa", {
          fecha: diaCorto(m.pausada_hasta ?? ""),
        }),
      };
    default:
      return { texto: t("tarjetas.sinMembresia") };
  }
}

/** "mié 7 oct, 18:00" en la zona de la sede. */
export function cuandoReserva(iso: string, zona: string | null): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona ?? undefined,
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}
