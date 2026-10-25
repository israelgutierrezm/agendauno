<script setup lang="ts">
import { computed } from "vue";

import type { Modo } from "@/marketing/modalidades";
import { pesosRedondos } from "@/marketing/precios";
import { usePreciosPublicos } from "@/marketing/preciosPublicos";

/**
 * Lo que se cobra aparte, con precio publicado (ADR 0188): el arranque acompañado
 * (gratis con el plan anual), la app con tu marca (solo en clases: la marca blanca es
 * de AgendaUno, ADR 0111, y hoy solo en Google Play) y los referidos. Los precios salen
 * de `GET /api/v1/precios` (los fija el superadmin); sin respuesta, los de respaldo.
 */
const props = defineProps<{ modo: Modo }>();
const precios = usePreciosPublicos();
// El plan anual es de citas (por adelantado); en clases se paga mes vencido.
const conAnual = computed(() => props.modo === "citas");
// En citas no hay membresías: se migran los bonos (ADR 0130).
const queMigramos = computed(() =>
  props.modo === "citas" ? "saldos y bonos" : "saldos y membresías",
);
const queDejamos = computed(() =>
  props.modo === "citas"
    ? "listos tus servicios, tu equipo y su horario"
    : "listas tus clases, paquetes y horario",
);
const RESPALDO = {
  arranque_minor: 390000,
  arranque_migracion_minor: 490000,
  app_marca_alta_minor: 490000,
  app_marca_mensual_minor: 99000,
};
const s = computed(() => ({ ...RESPALDO, ...(precios.datos.servicios ?? {}) }));
const meses = computed(() => precios.datos.referidos?.meses_gratis ?? 1);
</script>

<template>
  <div class="arranque" data-prueba="arranque-y-referidos">
    <article>
      <h3>Arranque acompañado</h3>
      <p>
        Si no quieres configurarlo solo: te dejamos {{ queDejamos }}, tu
        pasarela de pago y tu sitio con plantilla.
      </p>
      <p class="arranque-precio">
        {{ pesosRedondos(s.arranque_minor) }} MXN + IVA, un solo pago
      </p>
      <p class="arranque-nota">
        Con migración de tus clientes, {{ queMigramos }}:
        {{ pesosRedondos(s.arranque_migracion_minor) }} MXN + IVA.<template
          v-if="conAnual"
        >
          Sin costo con el plan anual.</template
        >
        Darte de alta tú mismo sigue sin costo.
      </p>
    </article>
    <article v-if="modo !== 'citas'" data-prueba="app-con-tu-marca">
      <h3>Tu app con tu marca</h3>
      <p>
        Tu propia app en Google Play, con tu nombre, tu logo y tus colores. Por
        ahora solo para Android. La app y la web de siempre siguen incluidas.
      </p>
      <p class="arranque-precio">
        {{ pesosRedondos(s.app_marca_alta_minor) }} MXN de alta y
        {{ pesosRedondos(s.app_marca_mensual_minor) }} MXN al mes, más IVA
      </p>
    </article>
    <article>
      <h3>Invita a otro negocio</h3>
      <p>
        Cuando el negocio que invitas paga su primer mes, los dos reciben
        {{ meses === 1 ? "un mes gratis" : `${meses} meses gratis` }}. Sin
        dinero en efectivo y sin letras chiquitas.
      </p>
      <p class="arranque-nota">Tu código está en Mi suscripción.</p>
    </article>
  </div>
</template>

<style scoped>
.arranque {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(16rem, 100%), 1fr));
  gap: 1rem;
}
.arranque article {
  padding: 1.25rem;
  border: 1px solid var(--borde);
  border-radius: 16px;
  background: var(--superficie);
}
.arranque h3 {
  font-weight: 600;
  font-size: 1.05rem;
}
.arranque p {
  margin-top: 0.5rem;
  font-size: 0.95rem;
}
.arranque-precio {
  font-weight: 600;
}
.arranque-nota {
  color: var(--texto-suave);
  font-size: 0.85rem !important;
}
</style>
