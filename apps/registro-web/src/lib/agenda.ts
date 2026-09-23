/**
 * Lógica pura de la agenda visual (sin Vue): fechas locales por zona, colores por
 * servicio, estado de una cita, cupo de una clase, carriles para sesiones
 * que se solapan y los indicadores (KPIs) de cada modalidad.
 */

export interface CitaTitular {
  reserva_id: string;
  cliente: string | null;
  estado: string; // estado de la reserva (confirmada, pendiente_pago…)
  asistencia: string | null; // presente | ausente | null
  orden_id?: string | null; // orden del servicio (si es de pago)
  por_cobrar?: boolean; // agendada por el negocio y aún sin cobrar en caja
}

export interface SesionAgenda {
  id: string;
  tipo?: "clase" | "cita";
  oferta: string | null;
  oferta_id: string | null;
  oferta_precio_clase: number | null;
  instructor: string | null;
  instructor_id: string | null;
  sala: string | null;
  inicia_en: string;
  termina_en: string;
  zona_horaria: string;
  capacidad: number | null;
  ocupados: number;
  en_espera: number;
  estado: string;
  cita?: CitaTitular | null;
}

export interface VentanaAtencion {
  instructor_id: string | null;
  sucursal_id: string | null;
  dia_semana: number; // ISO 1 (lunes) – 7 (domingo)
  hora_inicio: string; // HH:MM
  hora_fin: string;
}

// ---------------------------------------------------------------- fechas

export function pad2(n: number): string {
  return n < 10 ? `0${n}` : `${n}`;
}

/** Fecha local (YYYY-MM-DD) de un instante en la zona dada. */
export function fechaLocal(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: zona,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date(iso));
}

/** Minutos desde la medianoche local (0–1439) de un instante en la zona dada. */
export function minutosLocal(iso: string, zona: string): number {
  const partes = new Intl.DateTimeFormat("en-GB", {
    timeZone: zona,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).formatToParts(new Date(iso));
  const h = Number(partes.find((p) => p.type === "hour")?.value ?? "0") % 24;
  const m = Number(partes.find((p) => p.type === "minute")?.value ?? "0");
  return h * 60 + m;
}

export function duracionMin(
  s: Pick<SesionAgenda, "inicia_en" | "termina_en">,
): number {
  return Math.round(
    (new Date(s.termina_en).getTime() - new Date(s.inicia_en).getTime()) /
      60000,
  );
}

/** "HH:MM" a minutos. */
export function aMinutos(hhmm: string): number {
  const [h, m] = hhmm.split(":");
  return Number(h) * 60 + Number(m ?? 0);
}

/** Minutos a "HH:MM". */
export function aHora(min: number): string {
  return `${pad2(Math.floor(min / 60))}:${pad2(min % 60)}`;
}

/** Día ISO (1 = lunes … 7 = domingo) de una fecha YYYY-MM-DD. */
export function diaIso(ymd: string): number {
  const [y, m, d] = ymd.split("-").map(Number);
  const dow = new Date(y, m - 1, d).getDay();
  return dow === 0 ? 7 : dow;
}

// --------------------------------------------------------------- colores

export interface Tono {
  fondo: string;
  tinta: string;
}

/**
 * Paleta de servicios/clases. `tinta` es un tono medio (el de la demo de la
 * landing): va en el borde izquierdo del evento y, al 11 %, en su fondo; el texto
 * del evento usa el color normal. `fondo` es el pastel para el modo oscuro. El
 * color identifica el SERVICIO; el estado va aparte.
 */
export const PALETA_SERVICIO: readonly Tono[] = [
  { fondo: "#DCE9FF", tinta: "#0070FF" },
  { fondo: "#D6F2EA", tinta: "#12A68B" },
  { fondo: "#EBE3FF", tinta: "#9673DE" },
  { fondo: "#FCE0EB", tinta: "#D6457F" },
  { fondo: "#FDE7D6", tinta: "#E07A2E" },
  { fondo: "#DFF3FB", tinta: "#1C9BC7" },
  { fondo: "#FFF1CC", tinta: "#C28A12" },
  { fondo: "#E6F4D7", tinta: "#5E9B2A" },
];

function hash(texto: string): number {
  let h = 0;
  for (let i = 0; i < texto.length; i++) {
    h = (h * 31 + texto.charCodeAt(i)) >>> 0;
  }
  return h;
}

/**
 * Tono estable de un servicio: por su posición en el catálogo (sin choques entre
 * los primeros servicios); si no está en el catálogo, por hash del id/nombre.
 */
export function tonoServicio(
  ofertaId: string | null,
  catalogo: readonly string[],
  nombre: string | null = null,
): Tono {
  const i = ofertaId !== null ? catalogo.indexOf(ofertaId) : -1;
  const idx = i >= 0 ? i : hash(ofertaId ?? nombre ?? "");
  return PALETA_SERVICIO[idx % PALETA_SERVICIO.length];
}

export function iniciales(nombre: string | null): string {
  const partes = (nombre ?? "").trim().split(/\s+/).filter(Boolean);
  return partes
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join("");
}

// ------------------------------------------------------------ estado cita

export type EstadoCita =
  | "pendiente_pago"
  | "confirmada"
  | "llego"
  | "en_servicio"
  | "completada"
  | "no_asistio"
  | "cancelada";

/**
 * Color del punto de estado de una cita (variables del tema). La tarjeta lleva el
 * color del servicio; el del estado es solo un punto, para no competir con él.
 */
export const COLOR_ESTADO_CITA: Record<EstadoCita, string> = {
  confirmada: "var(--exito)",
  pendiente_pago: "var(--aviso)",
  llego: "var(--acento)",
  en_servicio: "var(--acento)",
  completada: "var(--texto-suave)",
  no_asistio: "var(--error)",
  cancelada: "var(--texto-suave)",
};

/**
 * Estado operativo de una cita a la hora `ahora`: la reserva dice si está pagada y la
 * asistencia si el cliente llegó; con la hora se distingue "llegó" (antes de
 * empezar), "en servicio" (durante) y "completada" (después).
 */
export function estadoCita(s: SesionAgenda, ahora: Date): EstadoCita {
  if (s.estado !== "programada" || !s.cita) {
    return "cancelada";
  }
  const { asistencia, estado } = s.cita;
  if (asistencia === "ausente") {
    return "no_asistio";
  }
  if (asistencia === "presente") {
    const t = ahora.getTime();
    if (t < new Date(s.inicia_en).getTime()) {
      return "llego";
    }
    return t < new Date(s.termina_en).getTime() ? "en_servicio" : "completada";
  }
  return estado === "pendiente_pago" ? "pendiente_pago" : "confirmada";
}

// ------------------------------------------------------------ cupo clase

export function pctCupo(
  s: Pick<SesionAgenda, "capacidad" | "ocupados">,
): number | null {
  return s.capacidad !== null && s.capacidad > 0
    ? Math.round((s.ocupados / s.capacidad) * 100)
    : null;
}

// --------------------------------------------------------------- carriles

export interface Intervalo {
  ini: number;
  fin: number;
}

/**
 * Reparte en carriles los intervalos que se solapan (como un calendario): devuelve,
 * por intervalo (en el orden recibido), su carril y cuántos carriles tiene su grupo.
 */
export function carriles(
  items: readonly Intervalo[],
): { carril: number; total: number }[] {
  const orden = items
    .map((it, i) => ({ ...it, i }))
    .sort((a, b) => a.ini - b.ini || a.fin - b.fin);
  const salida: { carril: number; total: number }[] = items.map(() => ({
    carril: 0,
    total: 1,
  }));

  let grupo: { i: number; ini: number; fin: number; carril: number }[] = [];
  let finGrupo = -1;
  const cerrar = (): void => {
    const fines: number[] = [];
    for (const it of grupo) {
      let c = fines.findIndex((f) => f <= it.ini);
      if (c === -1) {
        c = fines.length;
        fines.push(it.fin);
      } else {
        fines[c] = it.fin;
      }
      it.carril = c;
    }
    for (const it of grupo) {
      salida[it.i] = { carril: it.carril, total: Math.max(1, fines.length) };
    }
  };

  for (const it of orden) {
    if (grupo.length > 0 && it.ini >= finGrupo) {
      cerrar();
      grupo = [];
      finGrupo = -1;
    }
    grupo.push({ i: it.i, ini: it.ini, fin: it.fin, carril: 0 });
    finGrupo = Math.max(finGrupo, it.fin);
  }
  if (grupo.length > 0) {
    cerrar();
  }
  return salida;
}

/**
 * Tramos FUERA del horario de atención dentro de [desde, hasta) (minutos), dadas
 * las ventanas del día. Sin ventanas → sin sombreado (horario desconocido).
 */
export function fueraDeHorario(
  ventanas: readonly Intervalo[],
  desde: number,
  hasta: number,
): Intervalo[] {
  if (ventanas.length === 0) {
    return [];
  }
  const orden = [...ventanas].sort((a, b) => a.ini - b.ini);
  const fuera: Intervalo[] = [];
  let cursor = desde;
  for (const v of orden) {
    if (v.ini > cursor) {
      fuera.push({ ini: cursor, fin: Math.min(v.ini, hasta) });
    }
    cursor = Math.max(cursor, v.fin);
  }
  if (cursor < hasta) {
    fuera.push({ ini: cursor, fin: hasta });
  }
  return fuera.filter((f) => f.fin > f.ini);
}

// ------------------------------------------------------------------- KPIs

export interface KpisCitas {
  citas: number;
  enLocal: number;
  pendientesPago: number;
  porCobrarMinor: number;
  noAsistieron: number;
}

export function kpisCitas(
  sesiones: readonly SesionAgenda[],
  ahora: Date,
): KpisCitas {
  const k: KpisCitas = {
    citas: 0,
    enLocal: 0,
    pendientesPago: 0,
    porCobrarMinor: 0,
    noAsistieron: 0,
  };
  for (const s of sesiones) {
    const e = estadoCita(s, ahora);
    if (e === "cancelada") {
      continue;
    }
    k.citas++;
    if (e === "llego" || e === "en_servicio") {
      k.enLocal++;
    }
    // Por cobrar: pendiente de pago en línea o agendada por el negocio sin cobrar.
    if (
      e === "pendiente_pago" ||
      (e !== "no_asistio" && s.cita?.por_cobrar === true)
    ) {
      k.pendientesPago++;
      k.porCobrarMinor += s.oferta_precio_clase ?? 0;
    }
    if (e === "no_asistio") {
      k.noAsistieron++;
    }
  }
  return k;
}

export interface KpisClases {
  clases: number;
  ocupacionPct: number | null;
  reservados: number;
  enEspera: number;
  libresPorLlenar: number;
}

export function kpisClases(
  sesiones: readonly SesionAgenda[],
  ahora: Date,
): KpisClases {
  let clases = 0;
  let capacidad = 0;
  let reservados = 0;
  let enEspera = 0;
  let libres = 0;
  for (const s of sesiones) {
    if (s.estado !== "programada") {
      continue;
    }
    clases++;
    reservados += s.ocupados;
    enEspera += s.en_espera;
    if (s.capacidad !== null) {
      capacidad += s.capacidad;
      if (new Date(s.inicia_en).getTime() > ahora.getTime()) {
        libres += Math.max(0, s.capacidad - s.ocupados);
      }
    }
  }
  return {
    clases,
    ocupacionPct:
      capacidad > 0 ? Math.round((reservados / capacidad) * 100) : null,
    reservados,
    enEspera,
    libresPorLlenar: libres,
  };
}
