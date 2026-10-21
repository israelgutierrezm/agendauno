/**
 * La clave de un intento (ADR 0201): la misma mientras se repite exactamente lo mismo
 * (se perdió la respuesta, doble clic: el servidor devuelve lo que ya hizo, no lo hace
 * otra vez) y otra en cuanto cambia lo que se pide. Después de una respuesta, `listo()`:
 * lo siguiente es otro intento.
 */
export function intentoUnico(): {
  clave: (lo: unknown) => string;
  listo: () => void;
} {
  let firma: string | null = null;
  let clave = "";
  return {
    clave(lo: unknown): string {
      const nueva = JSON.stringify(lo);
      if (nueva !== firma) {
        firma = nueva;
        clave = crypto.randomUUID().replace(/-/g, "");
      }
      return clave;
    },
    listo(): void {
      firma = null;
    },
  };
}
