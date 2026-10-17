<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import { api } from "@/lib/api";
import { dinero } from "@/lib/formato";
import { fechaLarga } from "@/lib/planes";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * El pago de la suscripción que hay que atender, arriba de cada pantalla del panel
 * (ADR 0152): por vencer, rechazado por el banco, vencido o a días de suspender el
 * negocio. Solo para quien ve la facturación (al equipo no se le muestra la deuda) y
 * no en «Mi suscripción», donde ya está el detalle para pagar. El API decide si hay
 * algo que avisar; sin nada, no se ve.
 */
interface Aviso {
  nivel: "por_vencer" | "rechazado" | "vencido" | "por_suspender";
  monto_minor: number;
  moneda: string;
  vence_en: string;
  suspende_en: string | null;
  dias_para_suspension: number | null;
  proximo_intento_en: string | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const route = useRoute();

const aviso = ref<Aviso | null>(null);

const aplica = computed(
  () =>
    sesion.slug !== null &&
    sesion.puede("facturacion.ver") &&
    !sesion.suspendido,
);

async function cargar(): Promise<void> {
  if (!aplica.value) {
    aviso.value = null;
    return;
  }
  try {
    const { data } = await api.get<{ data: Aviso | null }>(
      `/api/v1/app/${sesion.slug}/renta/aviso`,
    );
    aviso.value = data.data;
  } catch {
    // Un aviso que no carga no estorba el trabajo: la renta sigue en su pantalla.
    aviso.value = null;
  }
}

// Al entrar, al cambiar de negocio o de rol, y al salir de «Mi suscripción» (quizá ya
// pagó ahí).
watch(() => [sesion.slug, sesion.usuario?.rol, aplica.value], cargar, {
  immediate: true,
});
watch(
  () => route.name,
  (ahora, antes) => {
    if (antes === "renta" && ahora !== "renta") void cargar();
  },
);

const visible = computed(
  () => aviso.value !== null && aplica.value && route.name !== "renta",
);

const TONO = {
  por_vencer: "info",
  rechazado: "aviso",
  vencido: "aviso",
  por_suspender: "peligro",
} as const;

const datos = computed(() => {
  const a = aviso.value;
  if (a === null) return {};
  return {
    monto: dinero(a.monto_minor, a.moneda, sesion.pais),
    vence: fechaLarga(a.vence_en),
    suspende: a.suspende_en ? fechaLarga(a.suspende_en) : "",
    dias: a.dias_para_suspension ?? 0,
    reintento: a.proximo_intento_en
      ? fechaLarga(a.proximo_intento_en.slice(0, 10))
      : "",
  };
});

const titulo = computed(() => {
  const a = aviso.value;
  if (a === null) return "";
  if (a.nivel === "por_suspender") {
    return a.dias_para_suspension === 0
      ? t("cobro.aviso.porSuspenderHoy")
      : t("cobro.aviso.porSuspender", datos.value);
  }
  return t(`cobro.aviso.${clave(a.nivel)}`, datos.value);
});

const texto = computed(() => {
  const a = aviso.value;
  if (a === null) return "";
  switch (a.nivel) {
    case "por_vencer":
      return t("cobro.aviso.porVencerTexto");
    case "rechazado":
      return a.proximo_intento_en
        ? t("cobro.aviso.rechazadoTextoReintento", datos.value)
        : t("cobro.aviso.rechazadoTexto");
    case "vencido":
      return a.suspende_en
        ? t(
            "cobro.aviso.vencidoTexto",
            datos.value,
            a.dias_para_suspension ?? 0,
          )
        : t("cobro.aviso.vencidoSinSuspension");
    default:
      return t("cobro.aviso.porSuspenderTexto", datos.value);
  }
});

function clave(nivel: Aviso["nivel"]): string {
  return nivel.replace(/_(\w)/g, (_, l: string) => l.toUpperCase());
}
</script>

<template>
  <div v-if="visible && aviso" class="ar-aviso" data-prueba="aviso-renta">
    <AvisoTono :tono="TONO[aviso.nivel]" :titulo="titulo">
      {{ texto }}
      <template #acciones>
        <RouterLink
          :to="{ name: 'renta' }"
          class="tu-btn tu-btn-primario whitespace-nowrap"
          data-prueba="aviso-renta-pagar"
          >{{ $t("cobro.aviso.pagar") }}</RouterLink
        >
      </template>
    </AvisoTono>
  </div>
</template>

<style scoped>
.ar-aviso {
  max-width: 72rem;
  margin: 1rem auto 0;
}
/* En celular el botón baja bajo el mensaje (al lado, el texto quedaba en una columna
   angosta), alineado con el texto y no con el ícono. */
@media (max-width: 640px) {
  .ar-aviso :deep(.tu-alerta) {
    flex-wrap: wrap;
  }
  .ar-aviso :deep(.tu-alerta > .shrink-0) {
    flex-basis: 100%;
    padding-left: 1.95rem;
  }
}
</style>
