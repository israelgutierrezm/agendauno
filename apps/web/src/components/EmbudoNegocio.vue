<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";

import CampanasNegocio from "@/components/CampanasNegocio.vue";

/**
 * El embudo del negocio (plan de mejoras C08): de la visita a su página hasta que su
 * cliente renueva. Cada paso dice la cantidad y su denominador («3 de 40»), no solo un
 * porcentaje; el servidor lo calcula sin datos personales.
 */
export interface Embudo {
  desde: string;
  hasta: string;
  sitio_a_solicitud: { visitas: number; solicitudes: number };
  solicitud_a_compra: { solicitudes: number; compraron: number };
  compra_a_asistencia: { compradores_nuevos: number; asistieron: number };
  renovacion: { por_renovar: number; renovadas: number };
  tiempo_respuesta: {
    solicitudes: number;
    atendidas: number;
    mediana_horas: number | null;
  };
}

const props = defineProps<{ embudo: Embudo; citas?: boolean }>();
const { t } = useI18n();

interface Paso {
  clave: "sitio" | "compra" | "asistencia" | "renovacion";
  cantidad: number;
  base: number;
}

const pasos = computed<Paso[]>(() => {
  const e = props.embudo;
  return [
    {
      clave: "sitio",
      cantidad: e.sitio_a_solicitud.solicitudes,
      base: e.sitio_a_solicitud.visitas,
    },
    {
      clave: "compra",
      cantidad: e.solicitud_a_compra.compraron,
      base: e.solicitud_a_compra.solicitudes,
    },
    {
      clave: "asistencia",
      cantidad: e.compra_a_asistencia.asistieron,
      base: e.compra_a_asistencia.compradores_nuevos,
    },
    {
      clave: "renovacion",
      cantidad: e.renovacion.renovadas,
      base: e.renovacion.por_renovar,
    },
  ];
});

// Sin denominador no hay porcentaje: «—», nunca un 0 % engañoso.
function pct(p: Paso): string {
  return p.base > 0 ? `${Math.round((p.cantidad / p.base) * 100)} %` : "—";
}
function ancho(p: Paso): string {
  return p.base > 0
    ? `${Math.min(100, Math.round((p.cantidad / p.base) * 100))}%`
    : "0";
}

const sinAtender = computed(
  () =>
    props.embudo.tiempo_respuesta.solicitudes -
    props.embudo.tiempo_respuesta.atendidas,
);
const mediana = computed(() => {
  const h = props.embudo.tiempo_respuesta.mediana_horas;
  if (h === null) {
    return "—";
  }
  return h < 1
    ? t("operacion.reportes.embudo.minutos", {
        n: Math.max(1, Math.round(h * 60)),
      })
    : t("operacion.reportes.embudo.horas", {
        n: new Intl.NumberFormat("es-MX", { maximumFractionDigits: 1 }).format(
          h,
        ),
      });
});
</script>

<template>
  <div data-prueba="embudo">
    <h2 class="mt-4 font-medium text-lg">
      {{ $t("operacion.reportes.embudo.titulo") }}
    </h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("operacion.reportes.embudo.subtitulo") }}
    </p>

    <ol class="mt-3 grid gap-3 sm:grid-cols-2">
      <li
        v-for="p in pasos"
        :key="p.clave"
        class="tu-card px-5 py-4"
        :data-paso="p.clave"
      >
        <div class="font-medium">
          {{ $t(`operacion.reportes.embudo.pasos.${p.clave}.titulo`) }}
        </div>
        <div class="mt-2 flex items-baseline gap-2">
          <span class="text-xl font-semibold tabular-nums">{{ pct(p) }}</span>
          <span
            class="text-sm tabular-nums"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t(`operacion.reportes.embudo.pasos.${p.clave}.cifra`, {
                n: p.cantidad,
                de: p.base,
              })
            }}
          </span>
        </div>
        <div
          class="mt-2 h-1.5 rounded-full overflow-hidden"
          :style="{ background: 'var(--borde)' }"
          aria-hidden="true"
        >
          <div
            class="h-full rounded-full"
            :style="{ width: ancho(p), background: 'var(--primario)' }"
          />
        </div>
        <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{
            $t(
              `operacion.reportes.embudo.pasos.${p.clave}.${citas ? "ayudaCitas" : "ayuda"}`,
            )
          }}
        </p>
      </li>
    </ol>

    <h3 class="mt-6 font-semibold">
      {{ $t("operacion.reportes.embudo.respuesta.titulo") }}
    </h3>
    <div class="mt-3 tu-card px-5 py-4 grid grid-cols-2 gap-4">
      <div>
        <div
          class="text-xl font-semibold tabular-nums"
          data-prueba="embudo-mediana"
        >
          {{ mediana }}
        </div>
        <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("operacion.reportes.embudo.respuesta.mediana") }}
        </div>
      </div>
      <div>
        <div class="text-xl font-semibold tabular-nums">
          {{ sinAtender }}
        </div>
        <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
          {{
            $t("operacion.reportes.embudo.respuesta.sinAtender", {
              de: embudo.tiempo_respuesta.solicitudes,
            })
          }}
        </div>
      </div>
    </div>
    <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("operacion.reportes.embudo.leyenda") }}
    </p>

    <!-- Por campaña (ADR 0144) -->
    <CampanasNegocio :desde="embudo.desde" :hasta="embudo.hasta" />
  </div>
</template>
