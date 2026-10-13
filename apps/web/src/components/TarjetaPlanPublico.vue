<script setup lang="ts">
import { useI18n } from "vue-i18n";

import { textoCobertura } from "@/lib/cobertura";
import { dinero as dineroDelPais } from "@/lib/formato";
import { adaptarTexto, type TerminosNegocio } from "@/lib/terminologia";

/**
 * Un plan como lo ve quien todavía no es cliente (M03): qué incluye, su precio, hasta
 * cuándo vale si lo compra hoy, sus restricciones (primera visita, permanencia, la sede
 * o la actividad) y el botón para quererlo. La usan la página del negocio y el widget
 * de planes (ADR 0141), con las palabras del negocio (ADR 0131).
 */
export interface PlanPublico {
  id?: string;
  todas_sucursales?: boolean;
  sucursales?: { id: string; nombre: string }[];
  nombre: string;
  tipo: string;
  precio_minor: number;
  moneda: string;
  ilimitado: boolean;
  creditos_incluidos: number | null;
  descripcion?: string | null;
  destacado?: boolean;
  primera_vez?: boolean;
  actividad?: string | null;
  unidades_por_ciclo?: number | null;
  vence_si_compra_hoy?: string | null;
  permanencia_meses?: number | null;
}

const props = withDefaults(
  defineProps<{
    plan: PlanPublico;
    terminos?: TerminosNegocio | null;
    // País del negocio (ISO 3166-1): los precios con sus números.
    pais?: string | null;
    variasSedes?: boolean;
    // La etiqueta «Primera visita» arriba (si los planes no van agrupados).
    marcarPrimeraVez?: boolean;
    boton: string;
  }>(),
  { terminos: null, pais: null, variasSedes: false, marcarPrimeraVez: true },
);
const emit = defineEmits<{ elegir: [] }>();

const { t } = useI18n();
function tn(
  clave: string,
  valores?: Record<string, unknown> | number,
  cuantos?: number,
): string {
  const texto =
    cuantos !== undefined
      ? t(clave, valores as Record<string, unknown>, cuantos)
      : typeof valores === "number"
        ? t(clave, valores)
        : valores !== undefined
          ? t(clave, valores)
          : t(clave);
  return props.terminos ? adaptarTexto(texto, props.terminos) : texto;
}
function dinero(minor: number, moneda: string): string {
  return dineroDelPais(minor, moneda, props.pais);
}
function fechaDia(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
    timeZone: "UTC",
  }).format(new Date(`${iso}T12:00:00Z`));
}
</script>

<template>
  <div
    class="tu-card flex h-full flex-col p-5"
    :class="{ 'tpp-destacado': plan.destacado }"
  >
    <div class="flex flex-wrap gap-2">
      <span class="tu-badge">{{
        tn(`escaparate.registro.tipos.${plan.tipo}`)
      }}</span>
      <span
        v-if="plan.destacado"
        class="tu-badge tpp-badge-marca"
        data-prueba="destacado"
        >{{ tn("escaparate.planes.destacado") }}</span
      >
      <span
        v-if="plan.primera_vez && marcarPrimeraVez"
        class="tu-badge"
        data-prueba="primera-vez"
        >{{ tn("escaparate.planes.primeraVez") }}</span
      >
    </div>
    <p class="mt-2 font-light">{{ plan.nombre }}</p>
    <p v-if="plan.descripcion" class="mt-1 text-sm">
      {{ plan.descripcion }}
    </p>
    <p
      v-if="variasSedes && plan.todas_sucursales !== undefined"
      class="mt-1 text-sm"
      style="color: var(--texto-suave)"
    >
      {{ textoCobertura(plan, $t) }}
    </p>
    <p class="mt-1 text-2xl font-extrabold">
      {{ dinero(plan.precio_minor, plan.moneda) }}
    </p>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      <template v-if="plan.ilimitado">{{
        tn("escaparate.ilimitado")
      }}</template>
      <template v-else-if="plan.creditos_incluidos">{{
        $t(
          "escaparate.creditos",
          { n: plan.creditos_incluidos / 1000 },
          plan.creditos_incluidos === 1000 ? 1 : 2,
        )
      }}</template>
      <template v-else-if="plan.unidades_por_ciclo">{{
        $t(
          "escaparate.planes.porMes",
          { n: plan.unidades_por_ciclo / 1000 },
          plan.unidades_por_ciclo === 1000 ? 1 : 2,
        )
      }}</template>
    </p>
    <!-- Qué cubre y hasta cuándo, sin letra chica (M03). -->
    <ul
      class="mt-3 mb-4 space-y-1 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      <li v-if="plan.actividad">
        {{
          tn("escaparate.planes.paraActividad", { actividad: plan.actividad })
        }}
      </li>
      <li v-if="plan.vence_si_compra_hoy" data-prueba="vence">
        {{
          tn("escaparate.planes.vence", {
            fecha: fechaDia(plan.vence_si_compra_hoy),
          })
        }}
      </li>
      <li v-else-if="plan.tipo === 'membresia'">
        {{ tn("escaparate.planes.renueva") }}
      </li>
      <li v-if="plan.primera_vez">
        {{ tn("escaparate.planes.primeraVezAyuda") }}
      </li>
      <li v-if="(plan.permanencia_meses ?? 0) > 0" data-prueba="permanencia">
        {{
          $t(
            "escaparate.planes.permanencia",
            { n: plan.permanencia_meses },
            plan.permanencia_meses ?? 0,
          )
        }}
      </li>
    </ul>
    <button
      type="button"
      class="tu-btn tu-btn-primario mt-auto w-full"
      data-prueba="lo-quiero"
      @click="emit('elegir')"
    >
      {{ boton }}
    </button>
  </div>
</template>

<style scoped>
/* El plan que el negocio recomienda: con el color de sus botones (su marca). */
.tpp-destacado {
  border-color: var(--marketing-cta, var(--primario));
  box-shadow: inset 0 0 0 1px var(--marketing-cta, var(--primario));
}
.tpp-badge-marca {
  color: var(--marketing-cta, var(--primario));
  border-color: color-mix(
    in srgb,
    var(--marketing-cta, var(--primario)) 40%,
    transparent
  );
}
</style>
