<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Cargador del logo del negocio por ARCHIVO: arrastrar y soltar o hacer clic para
 * elegir (nunca por URL). Sube a `POST /marca/logo` (imagen PNG/JPG/WebP ≤ 2 MB) y
 * permite quitarlo (`DELETE /marca/logo`). Emite el nuevo `logo_url` al padre. Se
 * ve como toda zona de carga de la app (`.tu-zona-archivo`).
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

// Contador: entrar al logo o al texto dispara «dragleave» en la zona.
const dentro = ref(0);
const arrastrando = computed(() => dentro.value > 0 && habilitado.value);
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
  dentro.value = 0;
  if (habilitado.value) {
    void procesar(evento.dataTransfer?.files?.[0]);
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
      class="tu-zona-archivo py-7"
      :class="{
        'tu-zona-archivo-activa': arrastrando,
        'tu-zona-archivo-ocupada': subiendo,
      }"
      role="button"
      :tabindex="habilitado ? 0 : -1"
      :aria-disabled="!puedeGestionar"
      :aria-label="$t('configuracion.logoArrastra')"
      @click="elegir"
      @keydown.enter.prevent="elegir"
      @keydown.space.prevent="elegir"
      @dragenter.prevent="dentro += 1"
      @dragover.prevent
      @dragleave.prevent="dentro = Math.max(0, dentro - 1)"
      @drop.prevent.stop="alSoltar"
    >
      <img
        v-if="logoUrl"
        :src="logoUrl"
        alt=""
        class="h-20 w-20 rounded-2xl object-cover"
        :style="{ boxShadow: 'var(--sombra)' }"
      />
      <span v-else class="tu-zona-archivo-icono"
        ><IconoNav nombre="imagen" :tam="24"
      /></span>

      <span class="tu-zona-archivo-texto">
        <template v-if="subiendo">{{
          $t("configuracion.logoSubiendo")
        }}</template>
        <template v-else-if="arrastrando">{{
          logoUrl
            ? $t("zonaArchivo.imagen.suelta")
            : $t("zonaArchivo.imagen.sueltaNueva")
        }}</template>
        <template v-else>
          {{ $t("asistente.logo.arrastra") }}
          <span class="tu-zona-archivo-elige">{{
            $t("asistente.logo.selecciona")
          }}</span>
        </template>
      </span>
      <span class="tu-zona-archivo-ayuda">
        {{ $t("asistente.logo.ayuda") }}
      </span>

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
