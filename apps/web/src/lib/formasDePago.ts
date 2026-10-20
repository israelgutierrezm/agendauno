/**
 * Cobro mixto (ADR 0177): un cobro en caja pagado con varias formas. Cada parte lleva
 * su forma y su monto en centavos; juntas suman exactamente lo que se cobra.
 */
export interface ParteCobro {
  metodo: string;
  monto_minor: number;
}

/** Las partes sirven: cada una mayor que cero, sin repetir forma y sumando el total. */
export function partesListas(
  partes: readonly ParteCobro[] | null,
  total: number,
): boolean {
  if (partes === null || partes.length < 2) {
    return false;
  }
  const metodos = new Set(partes.map((p) => p.metodo));
  return (
    metodos.size === partes.length &&
    partes.every((p) => Number.isInteger(p.monto_minor) && p.monto_minor > 0) &&
    partes.reduce((s, p) => s + p.monto_minor, 0) === total
  );
}

/** Lo que se paga en efectivo (de ahí sale el cambio). */
export function efectivoDe(partes: readonly ParteCobro[] | null): number {
  return (partes ?? [])
    .filter((p) => p.metodo === "efectivo")
    .reduce((s, p) => s + p.monto_minor, 0);
}
