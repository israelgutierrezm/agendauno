<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Kiosco de llegada (ADR 0183): las tabletas de recepción del negocio. Al activar una,
 * su enlace (con la llave) se muestra una sola vez; se abre en la tableta y queda
 * activada. Darla de baja corta su acceso.
 */
interface Kiosco {
  id: string;
  nombre: string;
  sucursal: { id: string; nombre: string } | null;
  ultimo_uso_en: string | null;
  activo: boolean;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const kioscos = ref<Kiosco[]>([]);
const sucursales = ref<{ id: string; nombre: string }[]>([]);
const nombre = ref("");
const sucursal = ref("");
const enlace = ref<string | null>(null);
const ocupado = ref(false);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  try {
    const [k, s] = await Promise.all([
      api.get<{ data: Kiosco[] }>(`${base.value}/kioscos`),
      api
        .get<{ data: { id: string; nombre: string }[] }>(
          `${base.value}/sucursales`,
        )
        .catch(() => ({ data: { data: [] } })),
    ]);
    kioscos.value = k.data.data;
    sucursales.value = s.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function activar(): Promise<void> {
  if (nombre.value.trim().length < 2) {
    return;
  }
  ocupado.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: Kiosco & { llave: string } }>(
      `${base.value}/kioscos`,
      { nombre: nombre.value.trim(), sucursal_id: sucursal.value || null },
    );
    enlace.value = `${window.location.origin}/estudio/${sesion.slug}/kiosco#llave=${encodeURIComponent(data.data.llave)}`;
    nombre.value = "";
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}

async function copiar(): Promise<void> {
  if (enlace.value) {
    await navigator.clipboard?.writeText(enlace.value).catch(() => undefined);
    toast.exito(t("kiosco.panel.copiado"));
  }
}

async function darDeBaja(k: Kiosco): Promise<void> {
  const si = await confirmar(
    t("kiosco.panel.darDeBajaConfirmar", { nombre: k.nombre }),
    { aceptar: t("kiosco.panel.darDeBaja"), peligro: true },
  );
  if (!si) {
    return;
  }
  try {
    await api.delete(`${base.value}/kioscos/${k.id}`);
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

function cuando(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: sesion.zonaHoraria ?? undefined,
  }).format(new Date(iso));
}

onMounted(cargar);
</script>

<template>
  <!-- Sin título propio: la pestaña «Kiosco de llegada» de Integraciones ya lo
       nombra. -->
  <div class="tu-card p-5 min-w-0" data-prueba="kioscos">
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("kiosco.panel.ayuda") }}
    </p>

    <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="activar">
      <label class="min-w-0 flex-1">
        <span class="tu-label">{{ $t("kiosco.panel.nombre") }}</span>
        <input
          v-model="nombre"
          class="tu-input"
          maxlength="80"
          :placeholder="$t('kiosco.panel.nombrePh')"
          data-prueba="kiosco-nombre"
        />
      </label>
      <label v-if="sucursales.length > 1" class="min-w-0">
        <span class="tu-label">{{ $t("kiosco.panel.sucursal") }}</span>
        <select v-model="sucursal" class="tu-input">
          <option value="">{{ $t("kiosco.panel.todas") }}</option>
          <option v-for="s in sucursales" :key="s.id" :value="s.id">
            {{ s.nombre }}
          </option>
        </select>
      </label>
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="ocupado || nombre.trim().length < 2"
        data-prueba="kiosco-activar"
      >
        {{ $t("kiosco.panel.agregar") }}
      </button>
    </form>
    <p v-if="error" class="tu-error-campo mt-2" role="alert">{{ error }}</p>

    <div
      v-if="enlace"
      class="mt-4 rounded-lg p-3 text-sm"
      :style="{ background: 'var(--superficie-2)' }"
      data-prueba="kiosco-enlace"
    >
      <p>{{ $t("kiosco.panel.enlace") }}</p>
      <p class="mt-2 break-all font-mono text-xs">{{ enlace }}</p>
      <button
        type="button"
        class="tu-btn tu-btn-secundario mt-2"
        @click="copiar"
      >
        {{ $t("kiosco.panel.copiar") }}
      </button>
    </div>

    <p
      v-if="kioscos.length === 0"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("kiosco.panel.vacio") }}
    </p>
    <ul v-else class="mt-4 divide-y" :style="{ borderColor: 'var(--borde)' }">
      <li
        v-for="k in kioscos"
        :key="k.id"
        class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
      >
        <span class="min-w-0">
          <span class="font-medium">{{ k.nombre }}</span>
          <span v-if="k.sucursal" :style="{ color: 'var(--texto-suave)' }">
            · {{ k.sucursal.nombre }}</span
          >
          <span class="block text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{
              !k.activo
                ? $t("kiosco.panel.baja")
                : k.ultimo_uso_en
                  ? $t("kiosco.panel.ultimoUso", {
                      cuando: cuando(k.ultimo_uso_en),
                    })
                  : $t("kiosco.panel.sinUso")
            }}
          </span>
        </span>
        <button
          v-if="k.activo"
          type="button"
          class="tu-btn tu-btn-peligro tu-btn-chico"
          @click="darDeBaja(k)"
        >
          {{ $t("kiosco.panel.darDeBaja") }}
        </button>
      </li>
    </ul>
  </div>
</template>
