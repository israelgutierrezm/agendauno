import { ref, type Ref } from "vue";
import { useRoute, useRouter } from "vue-router";

/**
 * Resultado con el que el cliente vuelve de la página de pago externa (Stripe
 * Checkout): `?pago=exito` o `?pago=cancelado` (`?tarjeta=…` al autorizar una
 * tarjeta para pago automático). Se lee una vez al abrir la pantalla y se quita de
 * la URL para que recargar no repita el aviso.
 */
export type RetornoPago = "exito" | "cancelado";

export function useRetornoPago(
  parametro: "pago" | "tarjeta" = "pago",
): Ref<RetornoPago | null> {
  const route = useRoute();
  const router = useRouter();
  const pago = route.query[parametro];
  const retorno = ref<RetornoPago | null>(
    pago === "exito" || pago === "cancelado" ? pago : null,
  );

  if (retorno.value !== null) {
    const resto = { ...route.query };
    delete resto[parametro];
    void router.replace({ query: resto });
  }

  return retorno;
}
