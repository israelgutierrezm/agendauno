<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * El nivel del cliente en cada actividad que tiene niveles (ADR 0187): una clase que
 * pide un nivel solo la reserva desde su cuenta quien tiene ese nivel o uno más alto.
 * Solo aparece si alguna actividad tiene niveles.
 */
interface Fila {
  actividad: { id: string; nombre: string };
  niveles: { id: string; nombre: string }[];
  nivel_id: string | null;
}

const props = defineProps<{ personaId: string }>();
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeEditar = computed(() => sesion.puede("miembros.gestionar"));
const filas = ref<Fila[]>([]);
const guardando = ref<string | null>(null);

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Fila[] }>(
      `${base.value}/miembros/${props.personaId}/niveles`,
    );
    filas.value = data.data;
  } catch {
    filas.value = [];
  }
}

async function cambiar(f: Fila, nivel: string): Promise<void> {
  guardando.value = f.actividad.id;
  try {
    await api.put(`${base.value}/miembros/${props.personaId}/niveles`, {
      actividad_id: f.actividad.id,
      nivel_id: nivel || null,
    });
    f.nivel_id = nivel || null;
    toast.exito(t("nivelesMiembro.guardado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <div v-if="filas.length > 0" data-prueba="niveles-miembro">
    <h2 class="text-sm font-semibold">{{ $t("nivelesMiembro.titulo") }}</h2>
    <p class="mt-0.5 text-xs" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("nivelesMiembro.ayuda") }}
    </p>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
      <label v-for="f in filas" :key="f.actividad.id">
        <span class="tu-label">{{ f.actividad.nombre }}</span>
        <select
          class="tu-input"
          :value="f.nivel_id ?? ''"
          :disabled="!puedeEditar || guardando === f.actividad.id"
          @change="cambiar(f, ($event.target as HTMLSelectElement).value)"
        >
          <option value="">{{ $t("nivelesMiembro.sinNivel") }}</option>
          <option v-for="n in f.niveles" :key="n.id" :value="n.id">
            {{ n.nombre }}
          </option>
        </select>
      </label>
    </div>
  </div>
</template>
