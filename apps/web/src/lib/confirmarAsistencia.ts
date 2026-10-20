import { api } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { dinero } from "@/lib/formato";

type Asistencia = "presente" | "ausente";
type Traducir = (
  llave: string,
  valores?: Record<string, unknown>,
  plural?: number,
) => string;

/** Qué pasaría con su crédito si no vino (lo calcula el API con su política). */
export interface EfectoInasistencia {
  con_credito: boolean;
  penaliza: boolean;
  tolerada: boolean;
  pierde_credito: boolean;
  unidades: number;
  /**
   * Sin crédito, lo que ya pagó y el negocio conserva: el anticipo de una cita
   * (ADR 0161) o el pago completo; null si no pagó nada.
   */
  conserva?: "anticipo" | "pago" | null;
  conserva_minor?: number | null;
  /** Con anticipo: lo demás que pagó, y cuánto de eso se le devuelve solo en línea. */
  devuelve_minor?: number;
  devuelve_en_linea_minor?: number;
  moneda?: string | null;
}

/** Lo lee del API antes de confirmar; null si no se pudo (se usa el texto general). */
export async function leerEfectoInasistencia(
  base: string,
  reservaId: string,
): Promise<EfectoInasistencia | null> {
  try {
    const { data } = await api.get<{ data: EfectoInasistencia }>(
      `${base}/reservas/${reservaId}/inasistencia`,
    );
    return data.data;
  } catch {
    return null;
  }
}

/** El efecto en una frase: lo que de verdad pasa con su saldo. */
export function textoInasistencia(
  t: Traducir,
  e: EfectoInasistencia | null,
): string | null {
  if (e === null) {
    return null;
  }
  if (!e.con_credito) {
    if (e.conserva && e.conserva_minor && e.moneda) {
      const monto = dinero(e.conserva_minor, e.moneda);
      if (e.conserva !== "anticipo") {
        return t("confirmaciones.asistencia.efecto.conservaPago", { monto });
      }
      // Solo el anticipo (ADR 0181): lo demás que pagó se le devuelve.
      const enLinea = e.devuelve_en_linea_minor ?? 0;
      const enCaja = (e.devuelve_minor ?? 0) - enLinea;
      return [
        t("confirmaciones.asistencia.efecto.conservaAnticipo", { monto }),
        enLinea > 0
          ? t("confirmaciones.asistencia.efecto.devuelveEnLinea", {
              monto: dinero(enLinea, e.moneda),
            })
          : null,
        enCaja > 0
          ? t("confirmaciones.asistencia.efecto.devuelveEnCaja", {
              monto: dinero(enCaja, e.moneda),
            })
          : null,
      ]
        .filter((x): x is string => x !== null)
        .join(" ");
    }
    return t("confirmaciones.asistencia.efecto.sinCredito");
  }
  if (e.pierde_credito) {
    const n = e.unidades / 1000;
    return t(
      "confirmaciones.asistencia.efecto.pierde",
      {
        n: new Intl.NumberFormat("es-MX", {
          maximumFractionDigits: 1,
        }).format(n),
      },
      n === 1 ? 1 : 2,
    );
  }
  return e.tolerada
    ? t("confirmaciones.asistencia.efecto.tolerada")
    : t("confirmaciones.asistencia.efecto.noPenaliza");
}

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
  // Lo que pasa con su crédito si no vino ({@link textoInasistencia}).
  efecto: string | null = null,
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
    const mensaje = efecto
      ? t("confirmaciones.asistencia.noVinoCon", { persona, efecto })
      : t("confirmaciones.asistencia.noVino", { persona });
    return confirmar(mensaje, {
      aceptar: nombre(ahora),
      peligro: true,
    });
  }
  return true;
}
