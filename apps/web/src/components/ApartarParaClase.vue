<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { api, mensajeDeError } from "@/lib/api";
import { dinero } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Productos al reservar (ADR 0194): lo que el negocio ofrece para su clase (agua,
 * calcetines antiderrapantes). Lo aparta aquí y lo paga al recogerlo en el mostrador;
 * puede quitarlo antes de la clase.
 */
interface Ofrecido {
  id: string;
  nombre: string;
  precio_minor: number;
  moneda: string;
  foto_url: string | null;
  disponibles: number;
}
interface Apartado {
  id: string;
  articulo: { id: string | null; nombre: string | null };
  cantidad: number;
  total_minor: number;
  moneda: string;
}

const props = defineProps<{ base: string; sesionId: string }>();
const emit = defineEmits<{ cerrar: [] }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const ofrecidos = ref<Ofrecido[]>([]);
const apartados = ref<Apartado[]>([]);
const cantidades = ref<Record<string, number>>({});
const cargando = ref(true);
const trabajando = ref<string | null>(null);
const error = ref<string | null>(null);
const url = computed(
  () => `${props.base}/mi/sesiones/${props.sesionId}/articulos`,
);

function precio(minor: number, moneda: string): string {
  return dinero(minor, moneda, sesion.pais);
}

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{
      data: { articulos: Ofrecido[]; apartados: Apartado[] };
    }>(url.value);
    ofrecidos.value = data.data.articulos;
    apartados.value = data.data.apartados;
    for (const o of ofrecidos.value) {
      cantidades.value[o.id] = cantidades.value[o.id] ?? 1;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function hacer(clave: string, accion: () => Promise<unknown>) {
  if (trabajando.value !== null) {
    return;
  }
  trabajando.value = clave;
  error.value = null;
  try {
    await accion();
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    trabajando.value = null;
  }
}

function apartar(o: Ofrecido): Promise<void> {
  return hacer(o.id, () =>
    api.post(url.value, {
      articulo_id: o.id,
      cantidad: cantidades.value[o.id] ?? 1,
    }),
  );
}

function quitar(a: Apartado): Promise<void> {
  return hacer(a.id, () => api.delete(`${props.base}/mi/apartados/${a.id}`));
}

onMounted(cargar);
</script>

<template>
  <div
    class="rounded-xl border p-4 space-y-3"
    :style="{ borderColor: 'var(--borde)' }"
    data-prueba="apartar-para-clase"
  >
    <div class="flex items-start justify-between gap-2">
      <div>
        <p class="font-medium">{{ t("apartados.titulo") }}</p>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ t("apartados.ayuda") }}
        </p>
      </div>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma tu-btn-chico"
        @click="emit('cerrar')"
      >
        {{ t("comun.cerrar") }}
      </button>
    </div>

    <ul v-if="apartados.length > 0" class="space-y-2 text-sm">
      <li
        v-for="a in apartados"
        :key="a.id"
        class="flex items-center justify-between gap-2"
        data-prueba="mi-apartado"
      >
        <span
          >{{ a.cantidad }} × {{ a.articulo.nombre }} ·
          <span class="tabular-nums">{{
            precio(a.total_minor, a.moneda)
          }}</span></span
        >
        <button
          type="button"
          class="tu-btn tu-btn-fantasma tu-btn-chico"
          :disabled="trabajando !== null"
          @click="quitar(a)"
        >
          {{ t("apartados.quitar") }}
        </button>
      </li>
    </ul>

    <p
      v-if="!cargando && ofrecidos.length === 0 && apartados.length === 0"
      class="text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t("apartados.nada") }}
    </p>
    <ul v-else class="space-y-2">
      <li
        v-for="o in ofrecidos"
        :key="o.id"
        class="flex flex-wrap items-center gap-3"
        :data-articulo="o.id"
      >
        <img
          v-if="o.foto_url"
          :src="o.foto_url"
          alt=""
          class="h-10 w-10 rounded-lg object-cover"
        />
        <span class="grow text-sm">
          {{ o.nombre }}
          <span
            class="block tabular-nums"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ precio(o.precio_minor, o.moneda) }}</span
          >
        </span>
        <select
          v-model.number="cantidades[o.id]"
          class="tu-input w-20"
          :aria-label="t('apartados.cantidad')"
        >
          <option v-for="n in o.disponibles" :key="n" :value="n">
            {{ n }}
          </option>
        </select>
        <button
          type="button"
          class="tu-btn tu-btn-contorno tu-btn-chico"
          :disabled="trabajando !== null"
          data-prueba="apartar"
          @click="apartar(o)"
        >
          {{ t("apartados.apartar") }}
        </button>
      </li>
    </ul>
    <AvisoTono v-if="error" tono="peligro" role="alert">{{ error }}</AvisoTono>
  </div>
</template>
