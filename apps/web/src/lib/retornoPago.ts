import { onBeforeUnmount, ref, type Ref } from "vue";
import { useRoute, useRouter } from "vue-router";

import { api } from "@/lib/api";

/**
 * Resultado con el que el cliente vuelve de la página de pago externa (Stripe
 * Checkout): `?pago=exito`, `?pago=pendiente` (aún sin aprobar: en tienda o en
 * revisión) o `?pago=cancelado` (`?tarjeta=…` al autorizar una
 * tarjeta para pago automático). Se lee una vez al abrir la pantalla y se quita de
 * la URL para que recargar no repita el aviso.
 */
export type RetornoPago = "exito" | "pendiente" | "cancelado";

export function useRetornoPago(
  parametro: "pago" | "tarjeta" = "pago",
): Ref<RetornoPago | null> {
  const route = useRoute();
  const router = useRouter();
  const pago = route.query[parametro];
  const retorno = ref<RetornoPago | null>(
    pago === "exito" || pago === "pendiente" || pago === "cancelado"
      ? pago
      : null,
  );

  if (retorno.value !== null) {
    const resto = { ...route.query };
    delete resto[parametro];
    delete resto.ref_pago;
    void router.replace({ query: resto });
  }

  return retorno;
}

/**
 * Cómo va el pago al volver. Volver con `exito` no es estar pagado (OpenPay vuelve
 * siempre así y la URL se puede escribir a mano): con la referencia que trae la URL
 * (`ref_pago`) se le pregunta al servidor hasta que el aviso de la pasarela lo
 * confirma, y solo entonces es `confirmado`, lo único que va en verde. Sin
 * confirmación en un minuto (o sin referencia), `en_proceso`: lo verá al llegar el
 * aviso, sin volver a pagar.
 */
export type EstadoRetornoPago =
  "confirmando" | "confirmado" | "en_proceso" | "pendiente" | "cancelado";

export function useConfirmacionPago(
  urlDe: (referencia: string) => string,
  alConfirmar?: () => void,
  { intervaloMs = 3000, intentos = 20 } = {},
): Ref<EstadoRetornoPago | null> {
  const route = useRoute();
  const referencia =
    typeof route.query.ref_pago === "string" ? route.query.ref_pago : "";
  const retorno = useRetornoPago();
  const estado = ref<EstadoRetornoPago | null>(
    retorno.value === "exito"
      ? referencia === ""
        ? "en_proceso"
        : "confirmando"
      : retorno.value,
  );

  let temporizador: ReturnType<typeof setTimeout> | undefined;
  let quedan = intentos;
  async function consultar(): Promise<void> {
    try {
      const { data } = await api.get<{ data: { estado: string } }>(
        urlDe(referencia),
      );
      if (data.data.estado === "confirmado") {
        estado.value = "confirmado";
        alConfirmar?.();
        return;
      }
      if (data.data.estado === "no_completado") {
        estado.value = "cancelado";
        return;
      }
    } catch {
      // Sin respuesta: se vuelve a preguntar.
    }
    quedan -= 1;
    if (quedan <= 0) {
      if (estado.value === "confirmando") {
        estado.value = "en_proceso";
      }
      return;
    }
    temporizador = setTimeout(() => void consultar(), intervaloMs);
  }

  if (
    referencia !== "" &&
    (estado.value === "confirmando" || estado.value === "pendiente")
  ) {
    void consultar();
  }
  onBeforeUnmount(() => clearTimeout(temporizador));

  return estado;
}

/** El texto de cada estado (`pagoEnLinea.*`). */
export function claveDeRetorno(estado: EstadoRetornoPago): string {
  return {
    confirmando: "pagoEnLinea.confirmando",
    confirmado: "pagoEnLinea.confirmado",
    en_proceso: "pagoEnLinea.enProceso",
    pendiente: "pagoEnLinea.pendiente",
    cancelado: "pagoEnLinea.cancelado",
  }[estado];
}

/** Verde solo confirmado; lo que aún no se sabe, neutro. */
export function colorDeRetorno(estado: EstadoRetornoPago): string {
  if (estado === "confirmado") {
    return "var(--exito)";
  }
  return estado === "cancelado" ? "var(--aviso)" : "var(--texto-suave)";
}
