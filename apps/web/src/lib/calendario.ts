/** Las apps de calendario que se nombran (con su miniatura, LogoCalendario.vue). */
export const APPS_CALENDARIO = [
  { marca: "google", nombre: "Google Calendar" },
  { marca: "apple", nombre: "Apple Calendar" },
  { marca: "outlook", nombre: "Outlook" },
] as const;

/**
 * "Agregar a mi calendario" de una reserva: enlace para Google Calendar y archivo
 * .ics (Apple Calendar, Outlook y los demás). Todo en el navegador, sin servidor.
 */
export interface EventoCalendario {
  /** Identificador estable (la reserva): el mismo evento no se duplica al reimportar. */
  uid: string;
  titulo: string;
  inicio: string;
  /** Si no se sabe, dura una hora. */
  fin?: string | null;
  lugar?: string | null;
  detalle?: string | null;
}

function finDe(e: EventoCalendario): Date {
  return e.fin
    ? new Date(e.fin)
    : new Date(new Date(e.inicio).getTime() + 3_600_000);
}

/** 20301008T150000Z */
function utc(d: Date): string {
  return d
    .toISOString()
    .replace(/[-:]/g, "")
    .replace(/\.\d{3}/, "");
}

export function enlaceGoogle(e: EventoCalendario): string {
  const p = new URLSearchParams({
    action: "TEMPLATE",
    text: e.titulo,
    dates: `${utc(new Date(e.inicio))}/${utc(finDe(e))}`,
  });
  if (e.lugar) {
    p.set("location", e.lugar);
  }
  if (e.detalle) {
    p.set("details", e.detalle);
  }
  return `https://calendar.google.com/calendar/render?${p.toString()}`;
}

function escapar(texto: string): string {
  return texto
    .replace(/\\/g, "\\\\")
    .replace(/;/g, "\\;")
    .replace(/,/g, "\\,")
    .replace(/\r?\n/g, "\\n");
}

/** Contenido .ics (RFC 5545, líneas con CRLF). */
export function archivoIcs(
  e: EventoCalendario,
  ahora: Date = new Date(),
): string {
  const lineas = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//AgendaUno//Reservas//ES",
    "CALSCALE:GREGORIAN",
    "METHOD:PUBLISH",
    "BEGIN:VEVENT",
    `UID:${e.uid}@agendauno`,
    `DTSTAMP:${utc(ahora)}`,
    `DTSTART:${utc(new Date(e.inicio))}`,
    `DTEND:${utc(finDe(e))}`,
    `SUMMARY:${escapar(e.titulo)}`,
    ...(e.lugar ? [`LOCATION:${escapar(e.lugar)}`] : []),
    ...(e.detalle ? [`DESCRIPTION:${escapar(e.detalle)}`] : []),
    "END:VEVENT",
    "END:VCALENDAR",
  ];
  return `${lineas.join("\r\n")}\r\n`;
}

/** Descarga el .ics (el teléfono o la computadora lo abren con su calendario). */
export function descargarIcs(e: EventoCalendario): void {
  const url = URL.createObjectURL(
    new Blob([archivoIcs(e)], { type: "text/calendar;charset=utf-8" }),
  );
  const a = document.createElement("a");
  a.href = url;
  a.download = `${e.titulo.replace(/[^\p{L}\p{N}]+/gu, "-").toLowerCase() || "reserva"}.ics`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
