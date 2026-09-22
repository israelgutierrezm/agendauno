<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const { t } = useI18n();

interface Oportunidad {
  id: string;
  oferta: string | null;
  actividad: string | null;
  sucursal: string | null;
  instructor: string | null;
  inicia_en: string;
  zona_horaria: string | null;
  capacidad: number;
  ocupados: number;
  libres: number;
  en_espera: number;
  ocupacion_pct: number | null;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedePromover = computed(() => sesion.puede("reservas.gestionar"));

const dias = ref(14);
const oportunidades = ref<Oportunidad[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const aviso = ref<string | null>(null);
const promoviendo = ref<string | null>(null);

function fechaHora(iso: string, zona: string | null): string {
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: zona ?? undefined,
  }).format(new Date(iso));
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Oportunidad[] }>(
      `${base.value}/sesiones/oportunidades`,
      {
        params: { dias: dias.value },
      },
    );
    oportunidades.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function promover(o: Oportunidad): Promise<void> {
  promoviendo.value = o.id;
  aviso.value = null;
  error.value = null;
  try {
    const { data } = await api.post<{ data: { ofrecidas: number } }>(
      `${base.value}/sesiones/${o.id}/promover`,
      {},
    );
    const n = data.data.ofrecidas;
    aviso.value =
      n > 0
        ? t("oportunidades.ofrecidas", { n })
        : t("oportunidades.sinPromover");
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    promoviendo.value = null;
  }
}

watch(dias, cargar);
onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      icono="oportunidades"
      :titulo="$t('oportunidades.titulo')"
      :subtitulo="$t('oportunidades.subtitulo')"
    />

    <!-- Horizonte -->
    <div class="mt-6 flex flex-wrap items-end gap-3">
      <div>
        <label class="tu-label" for="dias">{{
          $t("oportunidades.horizonte")
        }}</label>
        <select id="dias" v-model.number="dias" class="tu-input w-auto">
          <option :value="7">{{ $t("oportunidades.dias", { n: 7 }) }}</option>
          <option :value="14">{{ $t("oportunidades.dias", { n: 14 }) }}</option>
          <option :value="30">{{ $t("oportunidades.dias", { n: 30 }) }}</option>
        </select>
      </div>
    </div>

    <p v-if="aviso" class="mt-4 text-sm" style="color: var(--exito)">
      {{ aviso }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <p
        v-if="oportunidades.length === 0"
        class="mt-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("oportunidades.vacio") }}
      </p>

      <div v-else class="mt-6 grid gap-3 sm:grid-cols-2">
        <div v-for="o in oportunidades" :key="o.id" class="tu-card p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-semibold truncate">
                {{ o.oferta ?? o.actividad ?? "—" }}
              </p>
              <p
                class="text-xs mt-0.5"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ fechaHora(o.inicia_en, o.zona_horaria) }}
                <template v-if="o.sucursal"> · {{ o.sucursal }}</template>
                <template v-if="o.instructor"> · {{ o.instructor }}</template>
              </p>
            </div>
            <span class="tu-badge tu-badge-exito shrink-0">{{
              $t("oportunidades.libres", { n: o.libres })
            }}</span>
          </div>

          <div class="mt-3 flex items-center justify-between gap-3">
            <div class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              <span>{{ o.ocupados }}/{{ o.capacidad }}</span>
              <span v-if="o.ocupacion_pct !== null">
                · {{ o.ocupacion_pct }}%</span
              >
              <span
                v-if="o.en_espera > 0"
                class="ml-2 font-semibold"
                :style="{ color: 'var(--aviso)' }"
              >
                {{ $t("oportunidades.enEspera", { n: o.en_espera }) }}
              </span>
            </div>
            <button
              v-if="puedePromover && o.en_espera > 0"
              type="button"
              class="tu-btn tu-btn-primario text-xs px-3 py-1.5"
              :disabled="promoviendo === o.id"
              @click="promover(o)"
            >
              {{
                promoviendo === o.id
                  ? $t("oportunidades.promoviendo")
                  : $t("oportunidades.promover")
              }}
            </button>
          </div>
        </div>
      </div>
    </template>
  </section>
</template>
