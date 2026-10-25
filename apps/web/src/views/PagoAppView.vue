<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";

import AvisoRetornoPago from "@/components/AvisoRetornoPago.vue";
import { PRODUCTOS, productoActual } from "@/lib/producto";
import { useConfirmacionPago } from "@/lib/retornoPago";

/**
 * Regreso de un pago iniciado en la app (ADR 0211): en el navegador no hay sesión, así
 * que esta página es pública. Confirma el pago con el servidor (`ref_pago`) y ofrece
 * volver a la app con su esquema propio (`agendauno://`, `turnouno://` o el de una
 * marca blanca). Se leen los datos antes de que el composable limpie la URL.
 */
const route = useRoute();
const estudio =
  typeof route.query.estudio === "string" ? route.query.estudio : "";
const pedido = typeof route.query.app === "string" ? route.query.app : "";
const referencia =
  typeof route.query.ref_pago === "string" ? route.query.ref_pago : "";
// Solo un esquema de app (como lo valida la API): nunca http, javascript ni data.
const NO_APP = new Set([
  "http",
  "https",
  "javascript",
  "data",
  "file",
  "mailto",
  "tel",
  "intent",
  "blob",
  "about",
]);
const esquema =
  /^[a-z][a-z0-9.+-]{2,40}$/.test(pedido) && !NO_APP.has(pedido)
    ? pedido
    : null;

const estado = useConfirmacionPago(
  (ref) =>
    `/api/v1/app/${encodeURIComponent(estudio)}/pagos/${encodeURIComponent(ref)}/confirmacion`,
);
const enlaceApp = computed(() => {
  if (esquema === null) return null;
  const consulta = new URLSearchParams({
    ref: referencia,
    pago: estado.value ?? "",
  });
  return `${esquema}://pagos?${consulta.toString()}`;
});
const marca = PRODUCTOS[productoActual()].nombre;
</script>

<template>
  <section class="pago-app" aria-labelledby="pago-app-titulo">
    <h1 id="pago-app-titulo">Tu pago</h1>
    <AvisoRetornoPago v-if="estado" :estado="estado" />
    <p v-else class="pago-app-nota">
      Regresa a la app para ver cómo quedó tu pago.
    </p>
    <a
      v-if="enlaceApp"
      class="tu-btn tu-btn-primario"
      :href="enlaceApp"
      data-prueba="volver-a-la-app"
      >Volver a la app</a
    >
    <p class="pago-app-nota">
      Si la app de {{ marca }} no se abre, ábrela tú: el pago se ve en Pagos en
      cuanto se confirme.
    </p>
  </section>
</template>

<style scoped>
.pago-app {
  display: grid;
  justify-items: center;
  gap: 1rem;
  max-width: 28rem;
  margin: 0 auto;
  padding: clamp(2.5rem, 8vw, 5rem) 1.25rem;
  text-align: center;
}
.pago-app h1 {
  font-size: 1.6rem;
  font-weight: 500;
  letter-spacing: -0.02em;
}
.pago-app-nota {
  color: var(--texto-suave);
  font-size: 0.875rem;
  line-height: 1.6;
}
</style>
