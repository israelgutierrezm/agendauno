<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * "Califica tus clases": lo que el alumno tomó en los últimos días y aún no califica
 * (1 a 5 y un comentario opcional). Con filtro de fechas y paginado: si se juntan
 * varias, no es una lista interminable. Solo se muestra si hay algo por calificar.
 */
interface Pendiente {
  reserva_id: string;
  actividad: string | null;
  con: string | null;
  fecha: string | null;
}
interface Meta {
  page: number;
  ultima_pagina: number;
  total: number;
  per_page: number;
  dias_para_calificar?: number;
}

const POR_PAGINA = 5;

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const pendientes = ref<Pendiente[]>([]);
const meta = ref<Meta | null>(null);
const pagina = ref(1);
const desde = ref("");
const hasta = ref("");
const estrellas = ref<Record<string, number>>({});
const comentarios = ref<Record<string, string>>({});
const enviando = ref<string | null>(null);
// Hubo algo por calificar sin filtros: la tarjeta se queda aunque el filtro no dé nada.
const hayPorCalificar = ref(false);

const filtrando = computed(() => desde.value !== "" || hasta.value !== "");

/** AAAA-MM-DD de hoy (o de hace `dias`) en el calendario del dispositivo. */
function dia(diasAtras = 0): string {
  const d = new Date();
  d.setDate(d.getDate() - diasAtras);
  const dos = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`;
}
const minimo = computed(() =>
  meta.value?.dias_para_calificar !== undefined
    ? dia(meta.value.dias_para_calificar)
    : undefined,
);
const maximo = dia();

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
    const { data } = await api.get<{ data: Pendiente[]; meta?: Meta }>(
      `${base.value}/mi/resenas/pendientes`,
      {
        params: {
          page: pagina.value,
          per_page: POR_PAGINA,
          ...(desde.value !== "" ? { desde: desde.value } : {}),
          ...(hasta.value !== "" ? { hasta: hasta.value } : {}),
        },
      },
    );
    pendientes.value = data.data;
    meta.value = data.meta ?? null;
    pagina.value = data.meta?.page ?? 1;
    if (!filtrando.value) {
      hayPorCalificar.value = (data.meta?.total ?? data.data.length) > 0;
    }
  } catch {
    pendientes.value = [];
  }
}

function ir(n: number): void {
  pagina.value = n;
  void cargar();
}
function quitarFiltro(): void {
  desde.value = "";
  hasta.value = "";
}

// Cambiar las fechas vuelve a la primera página.
watch([desde, hasta], () => {
  pagina.value = 1;
  void cargar();
});

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
  <div
    v-if="hayPorCalificar || filtrando"
    class="tu-card overflow-hidden"
    data-prueba="calificar"
  >
    <div class="p-6 pb-3">
      <h2 class="font-medium text-lg">{{ $t("resenas.califica") }}</h2>
      <p
        v-if="meta?.dias_para_calificar"
        class="mt-1 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("resenas.ventana", { n: meta.dias_para_calificar }) }}
      </p>
      <div class="tu-filtros mt-4">
        <label class="flex items-center gap-2 text-sm">
          <span :style="{ color: 'var(--texto-suave)' }">{{
            $t("resenas.desde")
          }}</span>
          <input
            v-model="desde"
            class="tu-input"
            type="date"
            :min="minimo"
            :max="hasta || maximo"
            data-prueba="calificar-desde"
          />
        </label>
        <label class="flex items-center gap-2 text-sm">
          <span :style="{ color: 'var(--texto-suave)' }">{{
            $t("resenas.hasta")
          }}</span>
          <input
            v-model="hasta"
            class="tu-input"
            type="date"
            :min="desde || minimo"
            :max="maximo"
            data-prueba="calificar-hasta"
          />
        </label>
        <button
          v-if="filtrando"
          type="button"
          class="tu-btn tu-btn-fantasma text-sm"
          @click="quitarFiltro"
        >
          {{ $t("resenas.quitarFiltro") }}
        </button>
      </div>
    </div>

    <p
      v-if="pendientes.length === 0"
      class="px-6 pb-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("resenas.sinEnFechas") }}
    </p>
    <ul v-else class="px-6">
      <li
        v-for="p in pendientes"
        :key="p.reserva_id"
        class="border-t py-3"
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
    <PaginacionListado
      v-if="meta && meta.ultima_pagina > 1"
      :page="meta.page"
      :ultima-pagina="meta.ultima_pagina"
      :total="meta.total"
      :per-page="meta.per_page"
      @ir="ir"
    />
  </div>
</template>
