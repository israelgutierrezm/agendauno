/*
| Lo que una landing puede decir según si su producto recibe registros (ADR 0108). Lo
| usan las pruebas y `scripts/check-marketing.mjs` (por el bundle del prerender), para
| que las dos revisen lo mismo.
*/

/**
 * Con el registro cerrado (prelanzamiento), frases que ofrecen probar, registrarse o
 * contratar. Escritas para no chocar con lo legítimo: «prueba» a media frase
 * (sustantivo) o «empezar» no cuentan; «Prueba…» y «Empieza…» con mayúscula
 * (invitación) sí.
 */
export const FRASES_SOLO_CON_REGISTRO: readonly RegExp[] = [
  /\bgratis\b/i,
  /\bPru[eé]ba(lo)?\b/,
  /\bprobar(lo)?\b/i,
  /\bCrea tu (negocio|cuenta)\b/i,
  /\bCrear mi (negocio|cuenta)\b/i,
  /\bEmpieza\b/,
  /\bsin tarjeta\b/i,
  /\bCancela cuando quieras\b/i,
  /\bpermanencia\b/i,
  /\bReg[ií]strate\b/i,
];

/** Con el registro abierto, lo de la lista de interesados no se ve. */
export const FRASES_SOLO_EN_PRELANZAMIENTO: readonly RegExp[] = [
  /Quiero que me avisen/i,
  /Av[ií]senme/i,
  /\babre pronto\b/i,
  /\bPr[oó]ximamente\b/i,
  /As[ií] funcionar[aá]/i,
  /prelanzamiento/i,
];

/** Las frases de la lista que aparecen en el texto, con lo que las rodea. */
export function frasesEncontradas(
  texto: string,
  frases: readonly RegExp[],
): string[] {
  return frases.flatMap((frase) => {
    const encontrada = frase.exec(texto);
    if (encontrada === null) {
      return [];
    }
    const i = encontrada.index;
    const contexto = texto
      .slice(Math.max(0, i - 40), i + 40)
      .replace(/\s+/g, " ");
    return [`${String(frase)}: «…${contexto}…»`];
  });
}
