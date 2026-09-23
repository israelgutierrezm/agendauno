<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const { t } = useI18n();

interface Segmento {
  clave: string;
  etiqueta: string;
  descripcion: string;
  total: number;
}
interface Difusion {
  id: string;
  segmento: string;
  segmento_etiqueta: string;
  canal: string;
  asunto: string;
  total: number;
  enviada_en: string | null;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const segmentos = ref<Segmento[]>([]);
const difusiones = ref<Difusion[]>([]);
const cargando = ref(true);
const enviando = ref(false);
const error = ref<string | null>(null);
const exito = ref<number | null>(null);

const form = ref({
  segmento: "",
  canal: "interno" as "interno" | "email",
  asunto: "",
  cuerpo: "",
});

const segmentoSel = computed(() =>
  segmentos.value.find((s) => s.clave === form.value.segmento),
);
const destinatarios = computed(() => segmentoSel.value?.total ?? 0);
const puedeEnviar = computed(
  () =>
    form.value.segmento !== "" &&
    form.value.asunto.trim() !== "" &&
    form.value.cuerpo.trim() !== "",
);

// Ayuda de marcadores: se arma en el script para no meter `{{ }}` en el template
// (Vue lo interpretaría como interpolación anidada). El backend reemplaza estos
// tokens por los datos de cada alumno.
const marcadoresTexto = computed(() =>
  t("comunicaciones.marcadores", {
    a: "{{persona_nombre}}",
    b: "{{persona_email}}",
  }),
);

function canalTexto(canal: string): string {
  return canal === "email"
    ? t("comunicaciones.canalEmail")
    : t("comunicaciones.canalInterno");
}

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(iso));
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [seg, dif] = await Promise.all([
      api.get<{ data: Segmento[] }>(`${base.value}/comunicaciones/segmentos`),
      api.get<{ data: Difusion[] }>(`${base.value}/comunicaciones/difusiones`),
    ]);
    segmentos.value = seg.data.data;
    difusiones.value = dif.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function enviar(): Promise<void> {
  if (!puedeEnviar.value) {
    return;
  }
  enviando.value = true;
  error.value = null;
  exito.value = null;
  try {
    const { data } = await api.post<{ data: Difusion }>(
      `${base.value}/comunicaciones/difusiones`,
      { ...form.value },
    );
    exito.value = data.data.total;
    form.value.asunto = "";
    form.value.cuerpo = "";
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    enviando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('comunicaciones.titulo')" />

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <div
      v-if="exito !== null"
      class="mt-4 tu-card p-4 text-sm"
      :style="{ background: 'var(--exito-suave)', color: 'var(--exito)' }"
    >
      {{ $t("comunicaciones.enviada", { n: exito }) }}
    </div>

    <!-- Redactar difusión -->
    <div class="mt-6 tu-card p-6">
      <h3 class="text-sm font-semibold">
        {{ $t("comunicaciones.segmentoLabel") }}
      </h3>
      <p
        v-if="!cargando && segmentos.length === 0"
        class="mt-2 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comunicaciones.sinSegmento") }}
      </p>
      <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <button
          v-for="s in segmentos"
          :key="s.clave"
          type="button"
          class="text-left rounded-2xl border p-4 transition"
          :style="
            form.segmento === s.clave
              ? {
                  borderColor: 'var(--primario)',
                  background: 'var(--superficie-2)',
                }
              : { borderColor: 'var(--borde)' }
          "
          @click="form.segmento = s.clave"
        >
          <div class="flex items-center justify-between">
            <span class="font-semibold">{{ s.etiqueta }}</span>
            <span class="tu-badge">{{ s.total }}</span>
          </div>
          <span
            class="block text-xs mt-1"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ s.descripcion }}</span
          >
        </button>
      </div>

      <div class="mt-5 flex items-center gap-2">
        <span class="text-sm font-semibold">{{
          $t("comunicaciones.canalLabel")
        }}</span>
        <div class="flex gap-1">
          <button
            type="button"
            class="tu-badge cursor-pointer"
            :style="
              form.canal === 'interno'
                ? { background: 'var(--primario)', color: '#fff' }
                : {}
            "
            @click="form.canal = 'interno'"
          >
            {{ $t("comunicaciones.canalInterno") }}
          </button>
          <button
            type="button"
            class="tu-badge cursor-pointer"
            :style="
              form.canal === 'email'
                ? { background: 'var(--primario)', color: '#fff' }
                : {}
            "
            @click="form.canal = 'email'"
          >
            {{ $t("comunicaciones.canalEmail") }}
          </button>
        </div>
      </div>

      <label class="block mt-5 text-sm font-semibold">{{
        $t("comunicaciones.asuntoLabel")
      }}</label>
      <input
        v-model="form.asunto"
        type="text"
        maxlength="255"
        class="tu-input mt-1 w-full"
        :placeholder="$t('comunicaciones.asuntoPh')"
      />

      <label class="block mt-4 text-sm font-semibold">{{
        $t("comunicaciones.cuerpoLabel")
      }}</label>
      <textarea
        v-model="form.cuerpo"
        rows="4"
        maxlength="5000"
        class="tu-input mt-1 w-full"
        :placeholder="$t('comunicaciones.cuerpoPh')"
      ></textarea>
      <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ marcadoresTexto }}
      </p>

      <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
        <span class="text-sm" :style="{ color: 'var(--texto-suave)' }">{{
          $t("comunicaciones.destinatarios", { n: destinatarios })
        }}</span>
        <button
          class="tu-btn tu-btn-primario"
          type="button"
          :disabled="!puedeEnviar || enviando"
          @click="enviar"
        >
          {{
            enviando
              ? $t("comunicaciones.enviando")
              : $t("comunicaciones.enviar")
          }}
        </button>
      </div>
    </div>

    <!-- Historial -->
    <h3 class="mt-8 text-sm font-semibold">
      {{ $t("comunicaciones.historial") }}
    </h3>
    <p
      v-if="!cargando && difusiones.length === 0"
      class="mt-3 tu-card p-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comunicaciones.historialVacio") }}
    </p>
    <div v-else-if="difusiones.length > 0" class="mt-3 tu-card overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
            <th class="px-4 py-2 font-medium">
              {{ $t("comunicaciones.colFecha") }}
            </th>
            <th class="px-4 py-2 font-medium">
              {{ $t("comunicaciones.colSegmento") }}
            </th>
            <th class="px-4 py-2 font-medium">
              {{ $t("comunicaciones.colAsunto") }}
            </th>
            <th class="px-4 py-2 font-medium text-right">
              {{ $t("comunicaciones.colTotal") }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="d in difusiones"
            :key="d.id"
            class="border-t"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <td
              class="px-4 py-2 whitespace-nowrap"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ fecha(d.enviada_en) }}
            </td>
            <td class="px-4 py-2">
              <span class="tu-badge">{{ d.segmento_etiqueta }}</span>
              <span
                class="block text-xs mt-1"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ canalTexto(d.canal) }}</span
              >
            </td>
            <td class="px-4 py-2">{{ d.asunto }}</td>
            <td class="px-4 py-2 text-right font-semibold">{{ d.total }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
