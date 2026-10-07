import { describe, expect, it } from "vitest";

import {
  aMinor,
  aTexto,
  dinero,
  ejemploPrecio,
  localeDe,
  simboloMoneda,
} from "./formato";

// Intl separa con espacios duros; para comparar, espacios normales.
const plano = (s: string): string => s.replace(/\s/g, " ");

describe("idioma de los números según el país del negocio", () => {
  it("los de habla hispana con su país; otros con su idioma", () => {
    expect(localeDe("CO")).toBe("es-CO");
    expect(localeDe("es")).toBe("es-ES");
    expect(localeDe("US")).toBe("en-US");
    expect(localeDe("CA")).toBe("en-CA");
    expect(localeDe("BR")).toBe("pt-BR");
  });

  it("sin país o con uno sin idioma propio, como en México", () => {
    expect(localeDe(undefined)).toBe("es-MX");
    expect(localeDe(null)).toBe("es-MX");
    expect(localeDe("")).toBe("es-MX");
    expect(localeDe("FR")).toBe("es-MX");
  });
});

describe("dinero", () => {
  it("con la moneda y los separadores del país", () => {
    expect(dinero(125050, "MXN", "MX")).toBe("$1,250.50");
    expect(plano(dinero(125050, "COP", "CO"))).toBe("$ 1.250,50");
    expect(plano(dinero(125050, "EUR", "ES"))).toBe("1250,50 €");
  });

  it("sin país, como en México (lo de siempre)", () => {
    expect(dinero(89900, "MXN")).toBe("$899.00");
    expect(plano(dinero(89900, "USD"))).toBe("USD 899.00");
  });

  it("acepta opciones (p. ej. sin centavos)", () => {
    expect(dinero(100, "MXN", "MX", { maximumFractionDigits: 0 })).toBe("$1");
  });

  it("una moneda desconocida no rompe la pantalla", () => {
    expect(dinero(1250, "X1", "MX")).toBe("X1 12.50");
  });
});

describe("símbolo de la moneda junto a un precio", () => {
  it("el del país del negocio", () => {
    expect(simboloMoneda("MXN", "MX")).toBe("$");
    expect(simboloMoneda("EUR", "ES")).toBe("€");
    expect(simboloMoneda("PEN", "PE")).toBe("S/");
    expect(simboloMoneda("COP", "CO")).toBe("$");
  });

  it("sin símbolo propio en ese país, el código", () => {
    expect(simboloMoneda("USD", "MX")).toBe("USD");
    expect(simboloMoneda("X1", "MX")).toBe("X1");
  });
});

describe("leer un precio escrito a mano (aMinor)", () => {
  it("como se escribe en cada país", () => {
    expect(aMinor("25.000", "CO")).toBe(2500000);
    expect(aMinor("12,50", "ES")).toBe(1250);
    expect(aMinor("1,250.50", "MX")).toBe(125050);
    expect(aMinor("1.250,50", "AR")).toBe(125050);
    expect(aMinor("1.250,50", "ES")).toBe(125050);
    expect(aMinor("1 234,5", "CR")).toBe(123450);
    expect(aMinor("1.234,50", "CR")).toBe(123450);
  });

  it("enteros, decimales sueltos y ceros", () => {
    expect(aMinor("250", "MX")).toBe(25000);
    expect(aMinor("250", "CO")).toBe(25000);
    expect(aMinor("12.5", "MX")).toBe(1250);
    expect(aMinor(",50", "ES")).toBe(50);
    expect(aMinor("0", "MX")).toBe(0);
    expect(aMinor("1250000", "CO")).toBe(125000000);
  });

  it("sin país, como en México", () => {
    expect(aMinor("1,250.50")).toBe(125050);
    expect(aMinor("12.50", null)).toBe(1250);
  });

  it("con el símbolo o el código de la moneda y espacios", () => {
    expect(aMinor("$250", "MX")).toBe(25000);
    expect(aMinor("  $ 1,250.50  ", "MX")).toBe(125050);
    expect(aMinor("12,50 €", "ES")).toBe(1250);
    expect(aMinor("COP 25.000", "CO")).toBe(2500000);
    expect(aMinor("S/ 45.90", "PE")).toBe(4590);
    expect(aMinor("1 250", "MX")).toBe(125000);
  });

  it("lo que se presta a confusión no se adivina", () => {
    // En México, «1.250» podría ser mil doscientos cincuenta o 1.25.
    expect(aMinor("1.250", "MX")).toBeNull();
    // En México, «12,50» no es un número con miles bien agrupados.
    expect(aMinor("12,50", "MX")).toBeNull();
    // En Colombia, el formato de México se lee al revés.
    expect(aMinor("1,250.50", "CO")).toBeNull();
    expect(aMinor("1,250", "CO")).toBeNull();
    expect(aMinor("12.5", "ES")).toBeNull();
    expect(aMinor("1,25,000", "MX")).toBeNull();
  });

  it("lo que no es un precio", () => {
    expect(aMinor("", "MX")).toBeNull();
    expect(aMinor("   ", "MX")).toBeNull();
    expect(aMinor("abc", "MX")).toBeNull();
    expect(aMinor("12a5", "MX")).toBeNull();
    expect(aMinor("-50", "MX")).toBeNull();
    expect(aMinor(".", "MX")).toBeNull();
    expect(aMinor("1.2.3", "MX")).toBeNull();
    expect(aMinor("999999999999999999", "MX")).toBeNull();
  });
});

describe("un precio guardado, de vuelta al campo (aTexto)", () => {
  it("sin miles, con el decimal del país, y se vuelve a leer igual", () => {
    expect(aTexto(25000, "MX")).toBe("250");
    expect(aTexto(1250, "MX")).toBe("12.50");
    expect(aTexto(1250, "ES")).toBe("12,50");
    expect(aTexto(2500000, "CO")).toBe("25000");
    for (const [minor, pais] of [
      [125050, "MX"],
      [125050, "CO"],
      [1250, "ES"],
      [123450, "CR"],
      [99, "AR"],
    ] as const) {
      expect(aMinor(aTexto(minor, pais), pais)).toBe(minor);
    }
  });
});

describe("ejemplo de cómo escribir un precio", () => {
  it("con los separadores del país", () => {
    expect(ejemploPrecio("MX")).toBe("1,250.50");
    expect(ejemploPrecio("CO")).toBe("1.250,50");
    expect(aMinor(ejemploPrecio("CO"), "CO")).toBe(125050);
    expect(aMinor(ejemploPrecio("ES"), "ES")).toBe(125050);
  });
});
