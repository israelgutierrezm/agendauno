<script setup lang="ts">
import { onMounted, ref, watch } from "vue";

import { api, mensajeDeError } from "@/lib/api";

/**
 * El cliente cambia el horario de su reserva desde su cuenta (ADR 0044): una cita a
 * otro horario libre del mismo servicio y profesional, o una clase a otra fecha de la
 * misma clase. Si el negocio no lo permite (o ya no se puede), lo dice.
 */
interface Opciones {
  puede: boolean;
  motivo: string | null;
  tipo: "cita" | "clase";
  restantes: number;
  hasta: string;
  slots?: { inicia: string; termina: string }[];
  sesiones?: { id: string; inicia_en: string; zona_horaria: string }[];
}

const props = defineProps<{
  base: string;
  reservaId: string;
  zona: string;
  iniciaEn: string;
}>();

const emit = defineEmits<{ hecho: []; cerrar: [] }>();

function fechaLocal(iso: string): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: props.zona }).format(
    new Date(iso),
  );
}
function horaLocal(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: props.zona,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}
function cuando(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona,
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(iso));
}

const opciones = ref<Opciones | null>(null);
const fecha = ref(fechaLocal(props.iniciaEn));
const elegido = ref("");
const guardando = ref(false);
const error = ref<string | null>(null);

// Al cambiar de día rápido, una respuesta vieja no pisa los huecos del día elegido.
// Mientras llegan los nuevos no se ofrecen los del día anterior.
let pedido = 0;
const cargandoHuecos = ref(false);
async function cargar(): Promise<void> {
  const mio = ++pedido;
  error.value = null;
  elegido.value = "";
  cargandoHuecos.value = true;
  try {
    const { data } = await api.get<{ data: Opciones }>(
      `${props.base}/mi/reservas/${props.reservaId}/reprogramar`,
      { params: { fecha: fecha.value } },
    );
    if (mio === pedido) {
      opciones.value = data.data;
    }
  } catch (e) {
    if (mio === pedido) {
      error.value = mensajeDeError(e);
    }
  } finally {
    if (mio === pedido) {
      cargandoHuecos.value = false;
    }
  }
}

async function cambiar(): Promise<void> {
  if (elegido.value === "" || opciones.value === null || cargandoHuecos.value) {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    await api.post(
      `${props.base}/mi/reservas/${props.reservaId}/reprogramar`,
      opciones.value.tipo === "cita"
        ? { inicia_en_local: `${fecha.value} ${horaLocal(elegido.value)}:00` }
        : { sesion_id: elegido.value },
    );
    emit("hecho");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

watch(fecha, () => {
  if (opciones.value?.tipo === "cita") {
    void cargar();
  }
});
onMounted(cargar);
</script>

<template>
  <div
    class="mt-2 w-full space-y-3 rounded-lg border p-3 text-sm"
    :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
    role="group"
    :aria-label="$t('miReprogramar.titulo')"
  >
    <p v-if="error" style="color: var(--error)">{{ error }}</p>
    <template v-if="opciones">
      <p v-if="!opciones.puede" :style="{ color: 'var(--texto-suave)' }">
        {{ opciones.motivo }}
      </p>
      <template v-else>
        <p :style="{ color: 'var(--texto-suave)' }">
          {{
            $t("miReprogramar.hasta", {
              fecha: cuando(opciones.hasta, zona),
              n: opciones.restantes,
            })
          }}
        </p>

        <!-- Cita: otro horario libre -->
        <template v-if="opciones.tipo === 'cita'">
          <div>
            <label class="tu-label" :for="`mr-fecha-${reservaId}`">{{
              $t("miReprogramar.dia")
            }}</label>
            <input
              :id="`mr-fecha-${reservaId}`"
              v-model="fecha"
              type="date"
              class="tu-input w-auto"
            />
          </div>
          <p
            v-if="(opciones.slots ?? []).length === 0"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("miReprogramar.sinHorarios") }}
          </p>
          <div v-else class="flex flex-wrap gap-2">
            <button
              v-for="s in opciones.slots"
              :key="s.inicia"
              type="button"
              class="tu-btn text-xs px-3 py-1.5"
              :class="
                elegido === s.inicia ? 'tu-btn-primario' : 'tu-btn-fantasma'
              "
              :aria-pressed="elegido === s.inicia"
              @click="elegido = s.inicia"
            >
              {{ horaLocal(s.inicia) }}
            </button>
          </div>
        </template>

        <!-- Clase: otra fecha con lugar -->
        <template v-else>
          <p
            v-if="(opciones.sesiones ?? []).length === 0"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("miReprogramar.sinFechas") }}
          </p>
          <select
            v-else
            v-model="elegido"
            class="tu-input"
            :aria-label="$t('miReprogramar.otraFecha')"
          >
            <option value="" disabled>
              {{ $t("miReprogramar.otraFecha") }}
            </option>
            <option v-for="s in opciones.sesiones" :key="s.id" :value="s.id">
              {{ cuando(s.inicia_en, s.zona_horaria) }}
            </option>
          </select>
        </template>
      </template>
    </template>

    <div class="flex flex-wrap gap-2">
      <button
        v-if="opciones?.puede"
        type="button"
        class="tu-btn tu-btn-primario"
        :disabled="guardando || cargandoHuecos || elegido === ''"
        @click="cambiar"
      >
        {{ $t("miReprogramar.cambiar") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="guardando"
        @click="emit('cerrar')"
      >
        {{ $t("miReprogramar.volver") }}
      </button>
    </div>
  </div>
</template>
