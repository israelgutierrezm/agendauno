<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { api, mensajeDeError } from "@/lib/api";
import { descargarDeApi } from "@/lib/descargas";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Descargar todos los datos del negocio (ADR 0213): su dueño la pide, se prepara aparte
 * (llega un correo al quedar) y se descarga aquí unos días. También dado de baja, antes
 * de que se borren.
 */
interface Exportacion {
  id: string;
  estado: "en_proceso" | "lista" | "fallida" | "vencida";
  tamano_bytes: number | null;
  lista_en: string | null;
  vence_en: string | null;
  error: string | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}/negocio/exportar`);

const exportacion = ref<Exportacion | null>(null);
const ocupado = ref(false);
const error = ref<string | null>(null);
let revision: ReturnType<typeof setTimeout> | null = null;

function revisarLuego(): void {
  if (revision !== null) clearTimeout(revision);
  if (exportacion.value?.estado === "en_proceso") {
    revision = setTimeout(() => void cargar(), 5000);
  }
}

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Exportacion | null }>(base.value);
    exportacion.value = data.data;
    revisarLuego();
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function preparar(): Promise<void> {
  if (ocupado.value) return;
  ocupado.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: Exportacion }>(base.value, {});
    exportacion.value = data.data;
    revisarLuego();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}

async function descargar(): Promise<void> {
  const e = exportacion.value;
  if (e === null || ocupado.value) return;
  ocupado.value = true;
  error.value = null;
  try {
    await descargarDeApi(
      `${base.value}/${e.id}/descargar`,
      `datos-${sesion.slug}.zip`,
    );
  } catch (err) {
    error.value = mensajeDeError(err);
  } finally {
    ocupado.value = false;
  }
}

function tamano(bytes: number | null): string {
  if (bytes === null) return "";
  const mb = bytes / (1024 * 1024);
  return mb >= 1
    ? `${mb.toLocaleString("es-MX", { maximumFractionDigits: 1 })} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function fecha(iso: string | null): string {
  if (!iso) return "";
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
  }).format(new Date(iso));
}

const estado = computed(() => {
  const e = exportacion.value;
  if (e === null) return null;
  if (e.estado === "lista") {
    return t("datosNegocio.lista", {
      tamano: tamano(e.tamano_bytes),
      fecha: fecha(e.vence_en),
    });
  }
  return e.estado === "en_proceso" ? t("datosNegocio.preparando") : null;
});

onMounted(() => void cargar());
onBeforeUnmount(() => {
  if (revision !== null) clearTimeout(revision);
});
</script>

<template>
  <div class="ddn" data-prueba="descargar-datos-negocio">
    <div>
      <h3 class="ddn-titulo">{{ $t("datosNegocio.titulo") }}</h3>
      <p class="ddn-ayuda">{{ $t("datosNegocio.ayuda") }}</p>
      <p v-if="estado" class="ddn-estado" data-prueba="estado-datos">
        {{ estado }}
      </p>
      <AvisoTono
        v-if="exportacion?.error || error"
        tono="peligro"
        class="mt-2"
        role="alert"
      >
        {{ error ?? exportacion?.error }}
      </AvisoTono>
    </div>
    <button
      v-if="exportacion?.estado === 'lista'"
      type="button"
      class="tu-btn tu-btn-primario"
      :disabled="ocupado"
      data-prueba="descargar-datos"
      @click="descargar"
    >
      {{ $t("datosNegocio.descargar") }}
    </button>
    <button
      v-else
      type="button"
      class="tu-btn tu-btn-secundario"
      :disabled="ocupado || exportacion?.estado === 'en_proceso'"
      data-prueba="preparar-datos"
      @click="preparar"
    >
      {{ $t("datosNegocio.preparar") }}
    </button>
  </div>
</template>

<style scoped>
.ddn {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}
.ddn-titulo {
  font-weight: 600;
}
.ddn-ayuda {
  max-width: 40rem;
  margin-top: 0.25rem;
  color: var(--texto-suave);
  font-size: 0.875rem;
  line-height: 1.6;
}
.ddn-estado {
  margin-top: 0.5rem;
  font-size: 0.875rem;
}
</style>
