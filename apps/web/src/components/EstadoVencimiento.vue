<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";

import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { situacionDeFecha } from "@/lib/vencimiento";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * El estado de una fecha de cobro (renovación, gracia, vencimiento): punto y texto
 * («Próxima», «Vence hoy», «Vencida»…) y, debajo, la fecha y cuánto falta o pasó. Si
 * el servidor no manda la situación, se calcula con la fecha y hoy en el negocio.
 */
export type SituacionVencimiento =
  | "proxima"
  | "hoy"
  | "vencida"
  | "pendiente"
  | "en_mora"
  | "suspendida"
  | "pausada";

const props = defineProps<{
  // AAAA-MM-DD (o una fecha ISO: se toma el día).
  fecha: string;
  situacion?: SituacionVencimiento;
  // Otro texto para el estado (p. ej. «Gracia vencida»); el tono sigue a la situación.
  etiqueta?: string;
}>();

const { t, locale } = useI18n();
const sesion = useSesionTenantStore();

const calculada = computed(() =>
  situacionDeFecha(props.fecha, hoyEnNegocio(sesion.zonaHoraria)),
);
const situacion = computed<SituacionVencimiento>(
  () => props.situacion ?? calculada.value.situacion,
);
const TONOS: Record<SituacionVencimiento, string> = {
  proxima: "var(--texto-suave)",
  pausada: "var(--texto-suave)",
  hoy: "var(--aviso)",
  pendiente: "var(--aviso)",
  en_mora: "var(--aviso)",
  vencida: "var(--error)",
  suspendida: "var(--error)",
};
const fechaTexto = computed(() =>
  new Intl.DateTimeFormat(locale.value, {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(`${props.fecha.slice(0, 10)}T12:00:00`)),
);
const relativo = computed(() => {
  const { situacion: s, dias } = calculada.value;
  if (s === "hoy") {
    return t("vencimiento.hoy");
  }
  return s === "proxima"
    ? t("vencimiento.faltan", { n: dias }, dias)
    : t("vencimiento.hace", { n: dias }, dias);
});
</script>

<template>
  <span class="inline-flex flex-col" data-prueba="estado-vencimiento">
    <span
      class="tu-estado"
      :data-situacion="situacion"
      :style="{ '--tono': TONOS[situacion] }"
      >{{ etiqueta ?? t(`vencimiento.estados.${situacion}`) }}</span
    >
    <span class="text-xs" :style="{ color: 'var(--texto-suave)' }"
      >{{ fechaTexto }} · {{ relativo }}</span
    >
  </span>
</template>
