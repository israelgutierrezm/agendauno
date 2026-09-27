<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import type { ResumenTarjeta } from "@/lib/resumenTarjeta";

/**
 * Tarjeta de una persona en la cuadrícula de Miembros: quién es y cómo contactarla,
 * y de un vistazo su membresía o paquete (créditos, vencimiento, pausa), si debe, su
 * última visita y su próxima reserva. Solo lo que pide atención lleva color.
 */
const props = defineProps<{
  nombre: string;
  nombreCompleto: string;
  email: string | null;
  celular?: string | null;
  sede?: string | null;
  activo: boolean;
  nuevo?: boolean;
  resumen?: ResumenTarjeta | null;
}>();

const { t } = useI18n();

const creditos = computed(() => {
  const r = props.resumen?.membresia;
  if (!r || !r.tiene_acceso) {
    return null;
  }
  if (r.ilimitado) {
    return t("tarjetas.ilimitado");
  }
  const n = r.saldo_unidades / 1000;
  return t(
    "tarjetas.creditos",
    {
      n: new Intl.NumberFormat("es-MX", { maximumFractionDigits: 1 }).format(n),
    },
    n === 1 ? 1 : 2,
  );
});

function fechaCorta(ymd: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
  }).format(new Date(`${ymd}T12:00:00`));
}

// Qué pasa con su membresía, en una frase; con color solo si pide atención.
const estado = computed<{ texto: string; color?: string } | null>(() => {
  const m = props.resumen?.membresia;
  if (!m) {
    return null;
  }
  switch (m.estado) {
    case "vigente":
      return m.valido_hasta
        ? { texto: t("tarjetas.vence", { fecha: fechaCorta(m.valido_hasta) }) }
        : null;
    case "por_vencer":
      return {
        texto: t("tarjetas.vence", { fecha: fechaCorta(m.valido_hasta ?? "") }),
        color: "var(--aviso)",
      };
    case "vencida":
      return {
        texto: t("tarjetas.vencio", {
          fecha: fechaCorta(m.valido_hasta ?? ""),
        }),
        color: "var(--error)",
      };
    case "pausada":
      return {
        texto: t("tarjetas.enPausa", {
          fecha: fechaCorta(m.pausada_hasta ?? ""),
        }),
      };
    default:
      return { texto: t("tarjetas.sinMembresia") };
  }
});

function haceCuanto(iso: string): string {
  const dia = (d: Date) =>
    Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()) / 86_400_000;
  const dias = Math.round(dia(new Date(iso)) - dia(new Date()));
  return new Intl.RelativeTimeFormat("es-MX", { numeric: "auto" }).format(
    dias,
    "day",
  );
}

function cuando(iso: string, zona: string | null): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona ?? undefined,
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}

const contacto = computed(() =>
  [props.email, props.celular].filter(Boolean).join(" · "),
);
</script>

<template>
  <div class="tm">
    <div class="flex items-center gap-3">
      <AvatarIniciales :nombre="nombre" tam="lg" />
      <div class="min-w-0 flex-1">
        <div class="flex items-baseline justify-between gap-2">
          <span class="truncate font-medium"
            ><slot name="nombre">{{ nombreCompleto }}</slot></span
          >
          <span
            v-if="!activo"
            class="shrink-0 text-xs"
            :style="{ color: 'var(--aviso)' }"
            >{{ $t("miembros.suspendido") }}</span
          >
          <span
            v-else-if="nuevo"
            class="shrink-0 text-xs font-medium"
            :style="{ color: 'var(--aviso)' }"
            >{{ $t("miembros.nuevo") }}</span
          >
        </div>
        <p
          v-if="contacto"
          class="mt-0.5 truncate text-xs"
          :style="{ color: 'var(--texto-suave)' }"
          :title="contacto"
        >
          {{ contacto }}
        </p>
      </div>
    </div>

    <template v-if="resumen">
      <div class="tm-plan">
        <p class="truncate text-sm font-medium">
          {{ resumen.membresia.plan ?? $t("tarjetas.sinMembresia") }}
        </p>
        <p class="mt-0.5 text-xs" :style="{ color: 'var(--texto-suave)' }">
          <span v-if="creditos">{{ creditos }}</span>
          <template v-if="creditos && estado && resumen.membresia.plan">
            ·
          </template>
          <span
            v-if="estado && resumen.membresia.plan"
            :style="estado.color ? { color: estado.color } : undefined"
            >{{ estado.texto }}</span
          >
        </p>
        <p
          v-if="resumen.adeudo"
          class="mt-0.5 text-xs font-medium"
          :style="{ color: 'var(--error)' }"
        >
          {{ $t("tarjetas.adeudo") }}
        </p>
      </div>

      <dl class="tm-datos">
        <div>
          <dt>{{ $t("tarjetas.ultimaVisita") }}</dt>
          <dd>
            {{
              resumen.ultima_visita
                ? haceCuanto(resumen.ultima_visita)
                : $t("tarjetas.sinVisitas")
            }}
          </dd>
        </div>
        <div>
          <dt>{{ $t("tarjetas.proxima") }}</dt>
          <dd
            v-if="resumen.proxima"
            :title="resumen.proxima.clase ?? undefined"
          >
            {{
              cuando(resumen.proxima.inicia_en, resumen.proxima.zona_horaria)
            }}
          </dd>
          <dd v-else>{{ $t("tarjetas.sinReservas") }}</dd>
        </div>
        <div v-if="sede">
          <dt>{{ $t("tarjetas.sede") }}</dt>
          <dd>{{ sede }}</dd>
        </div>
      </dl>
    </template>
  </div>
</template>

<style scoped>
.tm-plan {
  margin-top: 0.85rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--borde);
}
.tm-datos {
  margin-top: 0.6rem;
  display: grid;
  gap: 0.3rem;
  font-size: 0.75rem;
}
.tm-datos > div {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
}
.tm-datos dt {
  color: var(--texto-suave);
  flex-shrink: 0;
}
.tm-datos dd {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: right;
}
</style>
