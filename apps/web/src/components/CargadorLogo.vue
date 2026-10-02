<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Cargador del logo del negocio por ARCHIVO: arrastrar y soltar o hacer clic para
 * elegir (nunca por URL). Sube a `POST /marca/logo` (imagen PNG/JPG/WebP ≤ 2 MB) y
 * permite quitarlo (`DELETE /marca/logo`). Emite el nuevo `logo_url` al padre.
 */
const props = withDefaults(
  defineProps<{ logoUrl: string | null; puedeGestionar?: boolean }>(),
  { puedeGestionar: true },
);
const emit = defineEmits<{ "update:logoUrl": [string | null] }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

// Coincide con la validación del backend (image | mimes:jpg,jpeg,png,webp | max:2048).
const MAX_BYTES = 2 * 1024 * 1024;
const TIPOS = ["image/png", "image/jpeg", "image/webp"];

const arrastrando = ref(false);
const subiendo = ref(false);
const error = ref<string | null>(null);
const entrada = ref<HTMLInputElement | null>(null);

const habilitado = computed(() => props.puedeGestionar && !subiendo.value);

function elegir(): void {
  if (habilitado.value) {
    entrada.value?.click();
  }
}

async function procesar(archivo: File | undefined | null): Promise<void> {
  error.value = null;
  if (archivo === undefined || archivo === null) {
    return;
  }
  if (!TIPOS.includes(archivo.type)) {
    error.value = t("configuracion.logoTipo");
    return;
  }
  if (archivo.size > MAX_BYTES) {
    error.value = t("configuracion.logoPeso");
    return;
  }
  subiendo.value = true;
  try {
    const cuerpo = new FormData();
    cuerpo.append("logo", archivo);
    const { data } = await api.post<{ data: { logo_url: string } }>(
      `${base.value}/marca/logo`,
      cuerpo,
    );
    emit("update:logoUrl", data.data.logo_url);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    subiendo.value = false;
    if (entrada.value) {
      entrada.value.value = "";
    }
  }
}

function alSoltar(evento: DragEvent): void {
  arrastrando.value = false;
  if (habilitado.value) {
    void procesar(evento.dataTransfer?.files?.[0]);
  }
}
function alArrastrar(dentro: boolean): void {
  if (habilitado.value) {
    arrastrando.value = dentro;
  }
}
function alSeleccionar(evento: Event): void {
  void procesar((evento.target as HTMLInputElement).files?.[0]);
}

async function quitar(): Promise<void> {
  if (!habilitado.value) {
    return;
  }
  if (
    !(await confirmar(t("confirmaciones.quitarLogo"), {
      aceptar: t("confirmaciones.quitar"),
      peligro: true,
    }))
  ) {
    return;
  }
  subiendo.value = true;
  error.value = null;
  try {
    await api.delete(`${base.value}/marca/logo`);
    emit("update:logoUrl", null);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    subiendo.value = false;
  }
}
</script>

<template>
  <div>
    <div
      class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed px-4 py-8 text-center transition"
      :class="{ 'cursor-pointer': habilitado }"
      :style="{
        borderColor: arrastrando ? 'var(--primario)' : 'var(--borde)',
        background: arrastrando ? 'var(--primario-suave)' : 'var(--fondo)',
        opacity: puedeGestionar ? 1 : 0.6,
      }"
      role="button"
      :tabindex="habilitado ? 0 : -1"
      :aria-label="$t('configuracion.logoArrastra')"
      @click="elegir"
      @keydown.enter.prevent="elegir"
      @keydown.space.prevent="elegir"
      @dragover.prevent="alArrastrar(true)"
      @dragenter.prevent="alArrastrar(true)"
      @dragleave.prevent="alArrastrar(false)"
      @drop.prevent="alSoltar"
    >
      <img
        v-if="logoUrl"
        :src="logoUrl"
        alt=""
        class="h-20 w-20 rounded-2xl object-cover"
        :style="{ boxShadow: 'var(--sombra)' }"
      />
      <span v-else class="cl-icono" aria-hidden="true">
        <svg
          width="30"
          height="30"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.6"
          stroke-linecap="round"
          stroke-linejoin="round"
        >
          <rect x="3.5" y="3.5" width="17" height="17" rx="2.5" />
          <circle cx="9" cy="9" r="1.75" />
          <path d="M20.5 15.5 15.5 10.5 5 20.5" />
        </svg>
      </span>

      <p class="text-sm font-medium">
        <template v-if="subiendo">{{
          $t("configuracion.logoSubiendo")
        }}</template>
        <template v-else>
          {{ $t("asistente.logo.arrastra") }}
          <span :style="{ color: 'var(--primario)' }">{{
            $t("asistente.logo.selecciona")
          }}</span>
        </template>
      </p>
      <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("asistente.logo.ayuda") }}
      </p>

      <input
        ref="entrada"
        type="file"
        accept="image/png,image/jpeg,image/webp"
        class="hidden"
        :disabled="!habilitado"
        @change="alSeleccionar"
      />
    </div>

    <div v-if="logoUrl && puedeGestionar" class="mt-2 flex flex-wrap gap-2">
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="subiendo"
        @click="elegir"
      >
        {{ $t("configuracion.logoSubir") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        style="color: var(--error)"
        :disabled="subiendo"
        @click="quitar"
      >
        {{ $t("configuracion.logoQuitar") }}
      </button>
    </div>

    <p v-if="error" class="mt-2 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
  </div>
</template>

<style scoped>
.cl-icono {
  display: inline-flex;
  height: 4rem;
  width: 4rem;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}
</style>
