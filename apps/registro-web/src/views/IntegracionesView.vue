<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Integracion {
  proveedor: string;
  activa: boolean;
  llaves_configuradas: string[];
}

const LLAVES = ["api_key", "base_url"];

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const integraciones = ref<Integracion[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const guardando = ref<string | null>(null);
const guardado = ref<string | null>(null);

const edicion = reactive<
  Record<string, { activa: boolean; llaves: Record<string, string> }>
>({});

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Integracion[] }>(
      `${base.value}/integraciones`,
    );
    integraciones.value = data.data;
    for (const i of data.data) {
      edicion[i.proveedor] = {
        activa: i.activa,
        llaves: { api_key: "", base_url: "" },
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
    const credenciales: Record<string, string> = {};
    for (const [k, v] of Object.entries(ed.llaves)) {
      if (v.trim() !== "") {
        credenciales[k] = v;
      }
    }
    const { data } = await api.put<{ data: Integracion }>(
      `${base.value}/integraciones/${proveedor}`,
      {
        activa: ed.activa,
        credenciales,
      },
    );
    const idx = integraciones.value.findIndex((i) => i.proveedor === proveedor);
    if (idx !== -1) {
      integraciones.value[idx] = data.data;
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

function configurada(proveedor: string, llave: string): boolean {
  return (
    integraciones.value
      .find((i) => i.proveedor === proveedor)
      ?.llaves_configuradas.includes(llave) ?? false
  );
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-5xl px-4 py-10">
    <EncabezadoSeccion
      icono="integraciones"
      :titulo="$t('integraciones.titulo')"
      :subtitulo="$t('integraciones.subtitulo')"
    />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <div v-if="!cargando" class="mt-6 space-y-4">
      <div v-for="i in integraciones" :key="i.proveedor" class="tu-card p-6">
        <div class="flex items-center justify-between gap-3">
          <h2 class="font-bold text-lg">
            {{ $t(`integraciones.proveedores.${i.proveedor}`) }}
          </h2>
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="edicion[i.proveedor].activa" type="checkbox" />
            {{ $t("integraciones.activa") }}
          </label>
        </div>

        <div class="mt-3 grid sm:grid-cols-2 gap-3">
          <div v-for="llave in LLAVES" :key="llave">
            <label class="tu-label" :for="`${i.proveedor}-${llave}`">
              {{
                llave === "api_key"
                  ? $t("integraciones.apiKey")
                  : $t("integraciones.baseUrl")
              }}
              <span
                v-if="configurada(i.proveedor, llave)"
                class="tu-badge tu-badge-exito ml-1"
                >✓ {{ $t("integraciones.configurada") }}</span
              >
            </label>
            <input
              :id="`${i.proveedor}-${llave}`"
              v-model="edicion[i.proveedor].llaves[llave]"
              class="tu-input"
              :type="llave === 'api_key' ? 'password' : 'text'"
              autocomplete="off"
              :placeholder="
                configurada(i.proveedor, llave)
                  ? '••••••'
                  : $t('integraciones.nuevaLlave')
              "
            />
          </div>
        </div>

        <div class="mt-4 flex items-center gap-3">
          <button
            class="tu-btn tu-btn-primario"
            :disabled="guardando === i.proveedor"
            @click="guardar(i.proveedor)"
          >
            {{
              guardando === i.proveedor
                ? $t("integraciones.guardando")
                : $t("integraciones.guardar")
            }}
          </button>
          <span
            v-if="guardado === i.proveedor"
            class="text-sm"
            :style="{ color: 'var(--exito)' }"
          >
            {{ $t("integraciones.guardado") }}
          </span>
        </div>
      </div>
    </div>
  </section>
</template>
