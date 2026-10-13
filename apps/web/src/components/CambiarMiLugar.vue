<script setup lang="ts">
import { onMounted, ref } from "vue";

import AvisoTono from "@/components/AvisoTono.vue";
import ElegirLugar from "@/components/ElegirLugar.vue";
import { api, mensajeDeError } from "@/lib/api";

/**
 * Elegir o cambiar el lugar numerado de su reserva (B06), por ejemplo al llegar desde
 * la lista de espera. Si mientras tanto alguien tomó ese lugar, el servidor lo rechaza:
 * se dice y se vuelven a cargar los libres (nunca se cambia a otro en silencio).
 */
interface Lugares {
  total: number;
  ocupados: number[];
  mio: number | null;
}

const props = defineProps<{
  base: string;
  reservaId: string;
  sesionId: string;
}>();
const emit = defineEmits<{ hecho: [lugar: number]; cerrar: [] }>();

const lugares = ref<Lugares | null>(null);
const elegido = ref<number | null>(null);
const error = ref<string | null>(null);
const guardando = ref(false);

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Lugares | null }>(
      `${props.base}/mi/sesiones/${props.sesionId}/lugares`,
    );
    lugares.value = data.data;
    elegido.value = data.data?.mio ?? null;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}
async function guardar(): Promise<void> {
  if (elegido.value === null) {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    await api.put(`${props.base}/mi/reservas/${props.reservaId}/lugar`, {
      lugar: elegido.value,
    });
    emit("hecho", elegido.value);
  } catch (e) {
    error.value = mensajeDeError(e);
    await cargar();
  } finally {
    guardando.value = false;
  }
}
onMounted(cargar);
</script>

<template>
  <div
    class="space-y-3 rounded-lg border p-3 text-sm"
    :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
    data-prueba="cambiar-mi-lugar"
  >
    <p v-if="!lugares && !error" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <ElegirLugar
      v-if="lugares"
      v-model="elegido"
      :total="lugares.total"
      :ocupados="lugares.ocupados"
      :mio="lugares.mio"
    />
    <AvisoTono v-if="error" tono="peligro" role="alert">{{ error }}</AvisoTono>
    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        class="tu-btn tu-btn-primario"
        :disabled="
          guardando || elegido === null || elegido === (lugares?.mio ?? null)
        "
        data-prueba="guardar-lugar"
        @click="guardar"
      >
        {{ $t("portal.lugares.guardar") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="guardando"
        @click="emit('cerrar')"
      >
        {{ $t("cancelacion.volver") }}
      </button>
    </div>
  </div>
</template>
