import { confirmar } from "@/lib/confirmar";

type Asistencia = "presente" | "ausente";
type Traducir = (llave: string, valores?: Record<string, unknown>) => string;

/**
 * Marcar asistencia en una lista mueve créditos. Se confirma lo que pesa: «No vino»
 * (según la política puede perder el crédito) y cualquier corrección (llegó ↔ no
 * vino, el crédito se ajusta). «Llegó» la primera vez no pregunta: es lo de todos los
 * días con la clase enfrente, y se puede corregir. true = seguir.
 */
export async function confirmarAsistencia(
  t: Traducir,
  persona: string,
  antes: string | null,
  ahora: Asistencia,
): Promise<boolean> {
  const nombre = (e: string): string =>
    e === "presente"
      ? t("confirmaciones.asistencia.llego")
      : t("confirmaciones.asistencia.noVinoCorto");
  if (antes === ahora) {
    return true;
  }
  if (antes === "presente" || antes === "ausente") {
    return confirmar(
      t("confirmaciones.asistencia.correccion", {
        persona,
        antes: nombre(antes),
        ahora: nombre(ahora),
      }),
      { aceptar: nombre(ahora), peligro: ahora === "ausente" },
    );
  }
  if (ahora === "ausente") {
    return confirmar(t("confirmaciones.asistencia.noVino", { persona }), {
      aceptar: nombre(ahora),
      peligro: true,
    });
  }
  return true;
}
