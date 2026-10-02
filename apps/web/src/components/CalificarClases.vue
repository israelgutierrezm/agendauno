<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * "Califica tus clases": lo que el alumno tomó en los últimos días y aún no califica
 * (1 a 5 y un comentario opcional). Solo se muestra si hay algo por calificar.
 */
interface Pendiente {
  reserva_id: string;
  actividad: string | null;
  con: string | null;
  fecha: string | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const pendientes = ref<Pendiente[]>([]);
const estrellas = ref<Record<string, number>>({});
const comentarios = ref<Record<string, string>>({});
const enviando = ref<string | null>(null);

function fecha(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        weekday: "short",
        day: "numeric",
        month: "short",
      }).format(new Date(iso))
    : "";
}

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Pendiente[] }>(
      `${base.value}/mi/resenas/pendientes`,
    );
    pendientes.value = data.data;
  } catch {
    pendientes.value = [];
  }
}

async function enviar(p: Pendiente): Promise<void> {
  const calificacion = estrellas.value[p.reserva_id] ?? 0;
  if (calificacion < 1) {
    return;
  }
  enviando.value = p.reserva_id;
  try {
    await api.post(`${base.value}/mi/reservas/${p.reserva_id}/resena`, {
      calificacion,
      comentario: comentarios.value[p.reserva_id]?.trim() || null,
    });
    toast.exito(t("resenas.gracias"));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    enviando.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <div v-if="pendientes.length > 0" class="tu-card p-6">
    <h2 class="font-medium text-lg">{{ $t("resenas.califica") }}</h2>
    <ul class="mt-2">
      <li
        v-for="p in pendientes"
        :key="p.reserva_id"
        class="border-t py-3 first:border-t-0"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <p class="text-sm font-medium">
          {{ p.actividad ?? "—" }}
          <span class="font-normal" :style="{ color: 'var(--texto-suave)' }">
            · {{ fecha(p.fecha) }}
            <template v-if="p.con">· {{ p.con }}</template>
          </span>
        </p>
        <div
          class="mt-2 flex gap-1"
          role="radiogroup"
          :aria-label="$t('resenas.calificacion')"
        >
          <button
            v-for="n in 5"
            :key="n"
            type="button"
            class="text-2xl leading-none"
            role="radio"
            :aria-checked="(estrellas[p.reserva_id] ?? 0) === n"
            :aria-label="$t('resenas.estrellas', { n })"
            :style="{
              color:
                n <= (estrellas[p.reserva_id] ?? 0)
                  ? 'var(--aviso)'
                  : 'var(--borde)',
            }"
            @click="estrellas[p.reserva_id] = n"
          >
            ★
          </button>
        </div>
        <div class="mt-2 flex flex-wrap gap-2">
          <input
            v-model="comentarios[p.reserva_id]"
            class="tu-input min-w-[12rem] flex-1"
            maxlength="1000"
            :placeholder="$t('resenas.comentarioPh')"
          />
          <button
            type="button"
            class="tu-btn tu-btn-primario text-sm"
            :disabled="enviando === p.reserva_id || !estrellas[p.reserva_id]"
            @click="enviar(p)"
          >
            {{ $t("resenas.enviar") }}
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>
