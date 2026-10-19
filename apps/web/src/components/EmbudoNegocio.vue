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
  sitio_a_solicitud: {
    visitas: number;
    // Solo las que llegaron por la página o el widget (pasan por una visita medida).
    solicitudes: number;
    // Las demás, sin comparar con las visitas.
    otras?: {
      enlace: number;
      embebido: number;
      sin_canal: number;
      equipo: number;
    };
  };
  solicitud_a_compra: { solicitudes: number; compraron: number };
  compra_a_asistencia: { compradores_nuevos: number; asistieron: number };
  renovacion: { por_renovar: number; renovadas: number };
  tiempo_respuesta: {
    solicitudes: number;
    atendidas: number;
    mediana_horas: number | null;
  };
  // Negocio de citas (R11): su propio recorrido, cada paso con su base.
  citas?: {
    // De la solicitud a una cita confirmada (ausente en un API anterior).
    interes?: { solicitudes: number; con_cita: number };
    agendadas: { agendadas: number; confirmadas: number };
    asistencia: { citas: number; asistieron: number };
    cobro: { atendidas: number; cobradas: number };
    siguiente: { clientes: number; volvieron: number };
  } | null;
}

const props = defineProps<{ embudo: Embudo; citas?: boolean }>();
const { t } = useI18n();

interface Paso {
  clave:
    | "sitio"
    | "compra"
    | "asistencia"
    | "renovacion"
    | "interes"
    | "confirmadas"
    | "asistenciaCita"
    | "cobro"
    | "siguiente";
  cantidad: number;
  base: number;
}

const pasos = computed<Paso[]>(() => {
  const e = props.embudo;
  const sitio: Paso = {
    clave: "sitio",
    cantidad: e.sitio_a_solicitud.solicitudes,
    base: e.sitio_a_solicitud.visitas,
  };
  // En citas: interés → cita confirmada → asistencia → cobro → siguiente visita (no
  // planes ni renovaciones).
  const c = e.citas;
  if (props.citas && c) {
    return [
      sitio,
      ...(c.interes
        ? [
            {
              clave: "interes" as const,
              cantidad: c.interes.con_cita,
              base: c.interes.solicitudes,
            },
          ]
        : []),
      {
        clave: "confirmadas",
        cantidad: c.agendadas.confirmadas,
        base: c.agendadas.agendadas,
      },
      {
        clave: "asistenciaCita",
        cantidad: c.asistencia.asistieron,
        base: c.asistencia.citas,
      },
      {
        clave: "cobro",
        cantidad: c.cobro.cobradas,
        base: c.cobro.atendidas,
      },
      {
        clave: "siguiente",
        cantidad: c.siguiente.volvieron,
        base: c.siguiente.clientes,
      },
    ];
  }
  return [
    sitio,
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

// Sin denominador no hay porcentaje: «—», nunca un 0 % engañoso. Más de lo que
// cabe en la base (p. ej. más solicitudes que visitas medidas) tampoco es una
// conversión: las poblaciones no se comparan.
function comparable(p: Paso): boolean {
  return p.base > 0 && p.cantidad <= p.base;
}
function pct(p: Paso): string {
  return comparable(p) ? `${Math.round((p.cantidad / p.base) * 100)} %` : "—";
}
function ancho(p: Paso): string {
  return comparable(p) ? `${Math.round((p.cantidad / p.base) * 100)}%` : "0";
}

// Las solicitudes que no pasan por una visita medida: aparte, solo la cantidad.
const otrasSolicitudes = computed(() => {
  const o = props.embudo.sitio_a_solicitud.otras;
  if (!o) return "";
  const partes = (
    [
      ["enlace", o.enlace],
      ["embebido", o.embebido],
      ["equipo", o.equipo],
      ["sinCanal", o.sin_canal],
    ] as const
  )
    .filter(([, n]) => n > 0)
    .map(([clave, n]) =>
      t(`operacion.reportes.embudo.otras.${clave}`, { n }, n),
    );
  return partes.length > 0
    ? t("operacion.reportes.embudo.otras.titulo", {
        lista: partes.join(" · "),
      })
    : "";
});

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
      {{
        citas && embudo.citas
          ? $t("operacion.reportes.embudo.tituloCitas")
          : $t("operacion.reportes.embudo.titulo")
      }}
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
        <p
          v-if="p.base > 0 && p.cantidad > p.base"
          class="mt-1 text-xs"
          :style="{ color: 'var(--aviso)' }"
          data-prueba="no-comparable"
        >
          {{ $t("operacion.reportes.embudo.noComparable") }}
        </p>
        <p
          v-if="p.clave === 'sitio' && otrasSolicitudes"
          class="mt-1 text-xs"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="otras-solicitudes"
        >
          {{ otrasSolicitudes }}
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
