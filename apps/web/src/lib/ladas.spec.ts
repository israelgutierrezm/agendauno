import { describe, expect, it } from "vitest";

import {
  LADAS,
  ladaDe,
  nombrePais,
  paisDeLada,
  paisesOrdenados,
  PAISES_FRECUENTES,
  separarInternacional,
  separarTelefono,
  unirTelefono,
} from "./ladas";

/*
| Países y ladas (ADR 0103): la lista completa ISO 3166-1 con su lada, los nombres en
| español y los celulares como «+<lada> <número>». Sin «+», el número es del país del
| negocio.
*/

describe("lista de países", () => {
  it("tiene todos los países con su lada, en dígitos", () => {
    expect(Object.keys(LADAS)).toHaveLength(250);
    expect(Object.values(LADAS).every((l) => /^\d{1,4}$/.test(l))).toBe(true);
    expect(ladaDe("MX")).toBe("52");
    expect(ladaDe("co")).toBe("57");
    expect(ladaDe("BR")).toBe("55");
    expect(ladaDe("XX")).toBeNull();
  });

  it("nombra los países en español", () => {
    expect(nombrePais("MX")).toBe("México");
    expect(nombrePais("US")).toBe("Estados Unidos");
    expect(nombrePais("ES")).toBe("España");
  });

  it("pone primero los más usados y luego los demás por nombre", () => {
    const lista = paisesOrdenados();
    expect(lista).toHaveLength(250);
    expect(
      lista.slice(0, PAISES_FRECUENTES.length).map((p) => p.codigo),
    ).toEqual([...PAISES_FRECUENTES]);
    const resto = lista.slice(PAISES_FRECUENTES.length).map((p) => p.nombre);
    expect(resto).toEqual([...resto].sort((a, b) => a.localeCompare(b, "es")));
    expect(lista.find((p) => p.codigo === "UY")).toEqual({
      codigo: "UY",
      lada: "598",
      nombre: "Uruguay",
    });
  });

  it("de una lada compartida elige el país preferido o el más usado", () => {
    expect(paisDeLada("1")).toBe("US");
    expect(paisDeLada("1", "CA")).toBe("CA");
    expect(paisDeLada("1", "MX")).toBe("US");
    expect(paisDeLada("+57")).toBe("CO");
    expect(paisDeLada("9999")).toBeNull();
  });
});

describe("celular con lada", () => {
  it("separa la lada del número", () => {
    expect(separarTelefono("+57 300 123 4567", "52")).toEqual({
      lada: "57",
      numero: "3001234567",
    });
    expect(separarTelefono("+1 (809) 555-1234", "52")).toEqual({
      lada: "1",
      numero: "8095551234",
    });
    // Sin espacio: la lada de la lista que coincide.
    expect(separarTelefono("+593991234567", "52")).toEqual({
      lada: "593",
      numero: "991234567",
    });
  });

  it("sin «+», el número es del país del negocio", () => {
    expect(separarTelefono("55 1234 5678", "52")).toEqual({
      lada: "52",
      numero: "5512345678",
    });
    expect(separarTelefono("3001234567", "57")).toEqual({
      lada: "57",
      numero: "3001234567",
    });
    expect(separarTelefono("", "34")).toEqual({ lada: "34", numero: "" });
  });

  it("con «+», la lada solo cuando ya se reconoce una", () => {
    expect(separarInternacional("+")).toBeNull();
    expect(separarInternacional("+5")).toBeNull();
    expect(separarInternacional("+57")).toEqual({ lada: "57", numero: "" });
    expect(separarInternacional("+57 ")).toEqual({ lada: "57", numero: "" });
    expect(separarInternacional("+573")).toEqual({ lada: "57", numero: "3" });
    expect(separarInternacional("3001234567")).toBeNull();
    // Lo que no es lada se queda con la del negocio.
    expect(separarTelefono("+5", "57")).toEqual({ lada: "57", numero: "5" });
  });

  it("une la lada y el número; sin número, vacío", () => {
    expect(unirTelefono("57", "300 123-4567")).toBe("+57 3001234567");
    expect(unirTelefono("52", "  ")).toBe("");
  });
});
