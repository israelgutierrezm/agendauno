import { describe, expect, it } from "vitest";

import es from "@/i18n/locales/es-MX";
import {
  FRASES_SOLO_CON_REGISTRO,
  FRASES_SOLO_EN_PRELANZAMIENTO,
  frasesEncontradas,
} from "./prelanzamiento";

/*
| Las frases que revisan las pruebas y scripts/check-marketing.mjs según el registro
| del producto: atrapan las invitaciones sin chocar con lo legítimo.
*/

const conRegistro = (texto: string) =>
  frasesEncontradas(texto, FRASES_SOLO_CON_REGISTRO);
const enPrelanzamiento = (texto: string) =>
  frasesEncontradas(texto, FRASES_SOLO_EN_PRELANZAMIENTO);

describe("frases según el registro del producto", () => {
  it("con el registro cerrado atrapa lo que ofrece probar, registrarse o contratar", () => {
    for (const texto of [
      "Prueba TurnoUno gratis durante 30 días, sin tarjeta.",
      "Pruébalo con la forma en que trabajas.",
      "Probar 30 días gratis",
      "Tienes 30 días para probarlo",
      "Empieza con tu negocio",
      "Cancela cuando quieras.",
      "Sin permanencia forzosa",
      "Crea tu negocio, activa tu cuenta",
      "Crear mi cuenta",
      es.landing.modalidad.probar,
      es.landing.pieHero,
      es.landing.confianza.cancelacion,
      es.entrar.registrar,
    ]) {
      expect(conRegistro(texto), texto).not.toEqual([]);
    }
  });

  it("no choca con lo legítimo", () => {
    for (const texto of [
      "Todo empieza con una agenda clara.",
      "Antes de empezar",
      "Configuración guiada para empezar",
      "Registras tu negocio, activas tu cuenta",
      es.landing.prelanzamiento.cta,
      es.landing.prelanzamiento.finalSubtitulo,
    ]) {
      expect(conRegistro(texto), texto).toEqual([]);
    }
  });

  it("con el registro abierto atrapa lo de la lista de interesados", () => {
    for (const texto of [
      es.landing.prelanzamiento.cta,
      es.nav.avisarmeCorto,
      es.landing.prelanzamiento.finalTitulo,
      es.landing.prelanzamiento.proximamente,
      es.landing.solucion.prelanzamiento.etiqueta,
    ]) {
      expect(enPrelanzamiento(texto), texto).not.toEqual([]);
    }
    expect(enPrelanzamiento(es.landing.modalidad.probar)).toEqual([]);
  });

  it("cuenta dónde la encontró", () => {
    expect(conRegistro("Hola. Prueba gratis.")).toEqual([
      "/\\bgratis\\b/i: «…Hola. Prueba gratis.…»",
      "/\\bPru[eé]ba(lo)?\\b/: «…Hola. Prueba gratis.…»",
    ]);
  });
});
