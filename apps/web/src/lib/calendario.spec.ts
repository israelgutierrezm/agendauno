import { describe, expect, it } from "vitest";

import { archivoIcs, enlaceGoogle } from "./calendario";

const evento = {
  uid: "r1",
  titulo: "Pole Nivel 1",
  inicio: "2030-01-08T15:00:00+00:00",
  fin: "2030-01-08T16:00:00+00:00",
  lugar: "Estudio Demo · Roma Norte",
};

describe("agregar a mi calendario", () => {
  it("arma el enlace de Google Calendar con las horas en UTC", () => {
    const url = new URL(enlaceGoogle(evento));
    expect(url.origin).toBe("https://calendar.google.com");
    expect(url.searchParams.get("action")).toBe("TEMPLATE");
    expect(url.searchParams.get("text")).toBe("Pole Nivel 1");
    expect(url.searchParams.get("dates")).toBe(
      "20300108T150000Z/20300108T160000Z",
    );
    expect(url.searchParams.get("location")).toBe("Estudio Demo · Roma Norte");
  });

  it("sin hora de fin, dura una hora", () => {
    const url = new URL(enlaceGoogle({ ...evento, fin: null }));
    expect(url.searchParams.get("dates")).toBe(
      "20300108T150000Z/20300108T160000Z",
    );
  });

  it("el .ics es válido para Apple y Outlook: CRLF, UID estable y texto escapado", () => {
    const ics = archivoIcs(
      { ...evento, titulo: "Clase, nivel; 1" },
      new Date("2030-01-01T00:00:00Z"),
    );
    expect(ics.startsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n")).toBe(true);
    expect(ics).toContain("UID:r1@agendauno\r\n");
    expect(ics).toContain("DTSTART:20300108T150000Z\r\n");
    expect(ics).toContain("DTEND:20300108T160000Z\r\n");
    expect(ics).toContain("SUMMARY:Clase\\, nivel\\; 1\r\n");
    expect(ics).toContain("LOCATION:Estudio Demo · Roma Norte\r\n");
    expect(ics.endsWith("END:VCALENDAR\r\n")).toBe(true);
  });
});
