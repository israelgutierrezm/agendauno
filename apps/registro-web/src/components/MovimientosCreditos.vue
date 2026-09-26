<script setup lang="ts">
import { onMounted, ref } from "vue";

import { api, mensajeDeError } from "@/lib/api";

/**
 * Historial de créditos de un plan del alumno (fase 1, punto 1.4): por qué cambió su
 * saldo ("Asistencia", "Cancelación tardía", "Créditos vencidos"…), de qué clase y
 * con qué saldo quedó.
 */
interface Movimiento {
  id: string;
  fecha: string | null;
  unidades: number;
  saldo_posterior: number;
  concepto: string;
  clase: {
    nombre: string | null;
    inicia_en: string;
    zona_horaria: string | null;
  } | null;
}

const props = defineProps<{ url: string }>();

const movimientos = ref<Movimiento[] | null>(null);
const error = ref<string | null>(null);

function creditos(u: number): string {
  const n = u / 1000;
  return `${n > 0 ? "+" : ""}${Number.isInteger(n) ? n : n.toFixed(1)}`;
}
function fecha(iso: string | null, zona?: string | null): string {
  if (iso === null) {
    return "";
  }
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona ?? undefined,
    day: "numeric",
    month: "short",
    hour: zona ? "2-digit" : undefined,
    minute: zona ? "2-digit" : undefined,
  }).format(new Date(iso));
}

onMounted(async () => {
  try {
    const { data } = await api.get<{ data: Movimiento[] }>(props.url);
    movimientos.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
});
</script>

<template>
  <div class="mt-2 w-full text-sm">
    <p v-if="error" style="color: var(--error)">{{ error }}</p>
    <p
      v-else-if="movimientos !== null && movimientos.length === 0"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("movimientosCredito.vacio") }}
    </p>
    <ul
      v-else-if="movimientos !== null"
      class="divide-y divide-[var(--borde)] border-t"
      :style="{ borderColor: 'var(--borde)' }"
    >
      <li
        v-for="m in movimientos"
        :key="m.id"
        class="flex items-center justify-between gap-3 py-2"
      >
        <span class="min-w-0">
          <span class="block font-medium">{{ m.concepto }}</span>
          <span class="block truncate" :style="{ color: 'var(--texto-suave)' }">
            <template v-if="m.clase"
              >{{ m.clase.nombre }} ·
              {{ fecha(m.clase.inicia_en, m.clase.zona_horaria) }}</template
            >
            <template v-else>{{ fecha(m.fecha) }}</template>
          </span>
        </span>
        <span class="shrink-0 text-right tabular-nums">
          <span class="block font-semibold">{{ creditos(m.unidades) }}</span>
          <span class="block text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{
              $t("movimientosCredito.saldo", {
                n: creditos(m.saldo_posterior).replace("+", ""),
              })
            }}
          </span>
        </span>
      </li>
    </ul>
  </div>
</template>
