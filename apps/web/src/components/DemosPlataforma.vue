<script setup lang="ts">
import axios from "axios";
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { mensajeDeError } from "@/lib/api";

/**
 * Superadmin: las demos agendadas desde la landing (ADR 0193), las próximas primero,
 * para llamarles y marcar cómo fueron. Qué horas se ofrecen y si se agendan se fija en
 * Parámetros (Demos).
 */
const props = defineProps<{ apiUrl: string; token: string }>();

interface Demo {
  id: string;
  producto: string;
  inicia_en: string;
  nombre: string;
  negocio: string;
  giro: string | null;
  correo: string;
  telefono: string;
  acepta_whatsapp: boolean;
  estado: "agendada" | "cancelada" | "atendida" | "no_asistio";
}

const { t } = useI18n();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});
const auth = () => ({ headers: { Authorization: `Bearer ${props.token}` } });

const demos = ref<Demo[]>([]);
const error = ref<string | null>(null);
const cambiando = ref<string | null>(null);

const TONOS: Record<Demo["estado"], string> = {
  agendada: "var(--aviso)",
  atendida: "var(--exito)",
  no_asistio: "var(--error)",
  cancelada: "var(--texto-suave)",
};

function cuando(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "America/Mexico_City",
  }).format(new Date(iso));
}

async function cargar(): Promise<void> {
  try {
    const { data } = await cliente.get<{ data: Demo[] }>(
      "/api/v1/plataforma/demos",
      auth(),
    );
    demos.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function marcar(d: Demo, estado: Demo["estado"]): Promise<void> {
  cambiando.value = d.id;
  try {
    const { data } = await cliente.put<{ data: Demo }>(
      `/api/v1/plataforma/demos/${d.id}`,
      { estado },
      auth(),
    );
    const i = demos.value.findIndex((x) => x.id === d.id);
    if (i >= 0) {
      demos.value[i] = data.data;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cambiando.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-card p-5" data-prueba="demos-plataforma">
    <h2 class="tu-titulo-seccion">{{ t("demosPlataforma.titulo") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ t("demosPlataforma.ayuda") }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-3" role="alert">
      {{ error }}
    </AvisoTono>
    <p
      v-if="demos.length === 0"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t("demosPlataforma.vacio") }}
    </p>
    <!-- Tabla en la computadora, ficha en el teléfono: quién arriba y las acciones
         al pie, siempre a la vista. -->
    <div v-else class="mt-4 relative overflow-x-auto tu-tabla-adaptable">
      <table class="tu-tabla text-sm">
        <thead>
          <tr>
            <th>{{ t("demosPlataforma.quien") }}</th>
            <th>{{ t("demosPlataforma.cuando") }}</th>
            <th>{{ t("demosPlataforma.contacto") }}</th>
            <th>{{ t("demosPlataforma.estado") }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="d in demos" :key="d.id" :data-demo="d.id">
            <td :data-etiqueta="t('demosPlataforma.quien')">
              {{ d.nombre }}
              <span class="tu-sub">{{ d.negocio }} · {{ d.producto }}</span>
            </td>
            <td
              class="whitespace-nowrap"
              :data-etiqueta="t('demosPlataforma.cuando')"
            >
              {{ cuando(d.inicia_en) }}
            </td>
            <td :data-etiqueta="t('demosPlataforma.contacto')">
              <!-- Un solo bloque: en la ficha del teléfono, todo junto al valor. -->
              <div>
                {{ d.telefono }}
                <span class="tu-sub">{{ d.correo }}</span>
              </div>
            </td>
            <td :data-etiqueta="t('demosPlataforma.estado')">
              <span class="tu-estado" :style="{ '--tono': TONOS[d.estado] }">{{
                t(`demosPlataforma.estados.${d.estado}`)
              }}</span>
            </td>
            <td class="tu-celda-acciones">
              <template v-if="d.estado === 'agendada'">
                <button
                  type="button"
                  class="tu-btn tu-btn-secundario tu-btn-chico"
                  :disabled="cambiando === d.id"
                  @click="marcar(d, 'atendida')"
                >
                  {{ t("demosPlataforma.atendida") }}
                </button>
                <button
                  type="button"
                  class="tu-btn tu-btn-secundario tu-btn-chico"
                  :disabled="cambiando === d.id"
                  @click="marcar(d, 'no_asistio')"
                >
                  {{ t("demosPlataforma.noAsistio") }}
                </button>
                <!-- Cancelar la demo: destructiva. -->
                <button
                  type="button"
                  class="tu-btn tu-btn-peligro tu-btn-chico"
                  :disabled="cambiando === d.id"
                  @click="marcar(d, 'cancelada')"
                >
                  {{ t("demosPlataforma.cancelar") }}
                </button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
