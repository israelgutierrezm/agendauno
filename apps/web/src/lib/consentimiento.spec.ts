import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  atribucionParaEnviar,
  capturarAtribucion,
  leerAtribucion,
  moverAtribucionSegunPreferencias,
} from "./atribucion";
import {
  EVENTO_PREFERENCIAS,
  VERSION_PREFERENCIAS,
  guardarPreferencias,
  leerPreferencias,
  visitanteDe,
} from "./consentimiento";

/*
| Preferencias de medición (ADR 0143): por ámbito; sin elegir, nada; el id de visitante
| solo con alguna autorización; con analítica la atribución sobrevive a la pestaña y con
| publicidad viajan los identificadores de clic.
*/

const AHORA = new Date("2026-10-12T18:00:00.000Z");

beforeEach(() => {
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("preferencias de medición", () => {
  it("cada ámbito guarda las suyas; el visitante solo con alguna autorización", () => {
    expect(leerPreferencias("plataforma:agendauno")).toBeNull();
    const avisado = vi.fn();
    window.addEventListener(EVENTO_PREFERENCIAS, avisado);

    const solo = guardarPreferencias(
      "negocio:grecon",
      { analitica: false, publicidad: false },
      AHORA,
    );
    expect(solo.visitante).toBeNull();
    const con = guardarPreferencias(
      "plataforma:agendauno",
      { analitica: true, publicidad: false },
      AHORA,
    );
    expect(con.visitante).toMatch(/^[a-f0-9]{32}$/);
    expect(visitanteDe("negocio:grecon")).toBeNull();
    expect(leerPreferencias("plataforma:agendauno")).toEqual({
      analitica: true,
      publicidad: false,
      en: AHORA.toISOString(),
      version: VERSION_PREFERENCIAS,
    });
    expect(avisado).toHaveBeenCalledTimes(2);

    // Al retirarla, el visitante se conserva para avisar el cambio.
    guardarPreferencias("plataforma:agendauno", {
      analitica: false,
      publicidad: false,
    });
    expect(visitanteDe("plataforma:agendauno")).toBe(con.visitante);
  });

  it("si cambia lo que se pide, se vuelve a preguntar", () => {
    window.localStorage.setItem(
      "tu.preferencias.plataforma:agendauno",
      JSON.stringify({
        analitica: true,
        publicidad: true,
        en: "x",
        version: 0,
      }),
    );
    expect(leerPreferencias("plataforma:agendauno")).toBeNull();
  });

  it("con analítica la atribución sobrevive a la pestaña; con publicidad viajan los clics", () => {
    const ambito = "negocio:grecon" as const;
    capturarAtribucion(
      ambito,
      new URLSearchParams(
        "utm_source=fb&utm_campaign=otono&fbclid=IwAR0abcDEF123",
      ),
      "",
      "grecon.agendauno.mx",
      AHORA,
    );
    expect(window.localStorage.getItem(`tu.atribucion.${ambito}`)).toBeNull();

    guardarPreferencias(ambito, { analitica: true, publicidad: true }, AHORA);
    moverAtribucionSegunPreferencias(ambito);
    expect(window.sessionStorage.getItem(`tu.atribucion.${ambito}`)).toBeNull();
    expect(leerAtribucion(ambito, AHORA).ultimo_toque?.campana).toBe("otono");
    expect(atribucionParaEnviar(ambito, AHORA)?.ultimo_toque?.fbclid).toBe(
      "IwAR0abcDEF123",
    );

    // Retirada la analítica, sale del navegador y queda solo en la pestaña.
    guardarPreferencias(ambito, { analitica: false, publicidad: false }, AHORA);
    moverAtribucionSegunPreferencias(ambito);
    expect(window.localStorage.getItem(`tu.atribucion.${ambito}`)).toBeNull();
    expect(leerAtribucion(ambito, AHORA).ultimo_toque?.campana).toBe("otono");
    expect(atribucionParaEnviar(ambito, AHORA)?.ultimo_toque?.fbclid).toBe(
      undefined,
    );
  });
});
