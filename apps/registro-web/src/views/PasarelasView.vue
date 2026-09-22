<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Pasarela {
  proveedor: string;
  activa: boolean;
  modo: string;
  llaves_configuradas: string[];
}

// Llaves que pide cada proveedor (para el formulario).
const LLAVES: Record<string, string[]> = {
  stripe: ["secret_key", "publishable_key", "webhook_secret"],
  openpay: [
    "merchant_id",
    "private_key",
    "public_key",
    "webhook_user",
    "webhook_password",
  ],
  mercadopago: ["access_token", "public_key", "webhook_secret"],
  ventanilla: [],
};

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const pasarelas = ref<Pasarela[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const guardando = ref<string | null>(null);
const guardado = ref<string | null>(null);

// Modelo editable por proveedor: activa, modo y llaves nuevas (write-only).
const edicion = reactive<
  Record<
    string,
    { activa: boolean; modo: string; llaves: Record<string, string> }
  >
>({});

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Pasarela[] }>(
      `${base.value}/pasarelas`,
    );
    pasarelas.value = data.data;
    for (const p of data.data) {
      edicion[p.proveedor] = {
        activa: p.activa,
        modo: p.modo,
        llaves: Object.fromEntries(
          (LLAVES[p.proveedor] ?? []).map((k) => [k, ""]),
        ),
      };
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function guardar(proveedor: string): Promise<void> {
  guardando.value = proveedor;
  guardado.value = null;
  error.value = null;
  try {
    const ed = edicion[proveedor];
    // Solo envia llaves con valor (merge en el backend).
    const credenciales: Record<string, string> = {};
    for (const [k, v] of Object.entries(ed.llaves)) {
      if (v.trim() !== "") {
        credenciales[k] = v;
      }
    }
    const { data } = await api.put<{ data: Pasarela }>(
      `${base.value}/pasarelas/${proveedor}`,
      {
        activa: ed.activa,
        modo: ed.modo,
        credenciales,
      },
    );
    // Refleja lo configurado y limpia los inputs (write-only).
    const idx = pasarelas.value.findIndex((p) => p.proveedor === proveedor);
    if (idx !== -1) {
      pasarelas.value[idx] = data.data;
    }
    for (const k of Object.keys(ed.llaves)) {
      ed.llaves[k] = "";
    }
    guardado.value = proveedor;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = null;
  }
}

function estaConfigurada(proveedor: string, llave: string): boolean {
  return (
    pasarelas.value
      .find((p) => p.proveedor === proveedor)
      ?.llaves_configuradas.includes(llave) ?? false
  );
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-3xl px-4 py-10">
    <h1 class="text-3xl font-extrabold">{{ $t("pasarelas.titulo") }}</h1>
    <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("pasarelas.subtitulo") }}
    </p>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <div v-if="!cargando" class="mt-6 space-y-4">
      <div v-for="p in pasarelas" :key="p.proveedor" class="tu-card p-6">
        <div class="flex items-center justify-between gap-3">
          <h2 class="font-bold text-lg">
            {{ $t(`pasarelas.proveedores.${p.proveedor}`) }}
          </h2>
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="edicion[p.proveedor].activa" type="checkbox" />
            {{ $t("pasarelas.activa") }}
          </label>
        </div>

        <div class="mt-3 grid sm:grid-cols-2 gap-3">
          <div>
            <label class="tu-label" :for="`modo-${p.proveedor}`">{{
              $t("pasarelas.modo")
            }}</label>
            <select
              :id="`modo-${p.proveedor}`"
              v-model="edicion[p.proveedor].modo"
              class="tu-input"
            >
              <option value="test">{{ $t("pasarelas.test") }}</option>
              <option value="live">{{ $t("pasarelas.live") }}</option>
            </select>
          </div>
        </div>

        <div
          v-if="LLAVES[p.proveedor].length > 0"
          class="mt-3 grid sm:grid-cols-2 gap-3"
        >
          <div v-for="llave in LLAVES[p.proveedor]" :key="llave">
            <label class="tu-label" :for="`${p.proveedor}-${llave}`">
              {{ llave }}
              <span
                v-if="estaConfigurada(p.proveedor, llave)"
                class="tu-badge tu-badge-exito ml-1"
                >✓ {{ $t("pasarelas.configurada") }}</span
              >
            </label>
            <input
              :id="`${p.proveedor}-${llave}`"
              v-model="edicion[p.proveedor].llaves[llave]"
              class="tu-input"
              type="password"
              autocomplete="off"
              :placeholder="
                estaConfigurada(p.proveedor, llave)
                  ? '••••••'
                  : $t('pasarelas.nuevaLlave')
              "
            />
          </div>
        </div>
        <p v-else class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("pasarelas.sinLlaves") }}
        </p>

        <div class="mt-4 flex items-center gap-3">
          <button
            class="tu-btn tu-btn-primario"
            :disabled="guardando === p.proveedor"
            @click="guardar(p.proveedor)"
          >
            {{
              guardando === p.proveedor
                ? $t("pasarelas.guardando")
                : $t("pasarelas.guardar")
            }}
          </button>
          <span
            v-if="guardado === p.proveedor"
            class="text-sm"
            :style="{ color: 'var(--exito)' }"
          >
            {{ $t("pasarelas.guardado") }}
          </span>
        </div>
      </div>
    </div>
  </section>
</template>
