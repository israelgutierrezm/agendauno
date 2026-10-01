<script setup lang="ts">
import { reactive, ref } from "vue";
import { useI18n } from "vue-i18n";

import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

export interface MiembroEditable {
  id: string;
  nombre: string;
  segundo_nombre: string | null;
  primer_apellido: string | null;
  segundo_apellido: string | null;
  email: string | null;
  activo: boolean;
  es_facturable: boolean;
  archivado: boolean;
}

const props = defineProps<{ miembro: MiembroEditable }>();
const emit = defineEmits<{
  (e: "cerrar"): void;
  (e: "guardado", m: MiembroEditable): void;
}>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = `/api/v1/app/${sesion.slug}`;

const form = reactive({
  nombre: props.miembro.nombre,
  segundo_nombre: props.miembro.segundo_nombre ?? "",
  primer_apellido: props.miembro.primer_apellido ?? "",
  segundo_apellido: props.miembro.segundo_apellido ?? "",
  email: props.miembro.email ?? "",
  activo: props.miembro.activo,
  es_facturable: props.miembro.es_facturable,
  archivado: props.miembro.archivado,
});
const guardando = ref(false);
const error = ref<string | null>(null);

// Baja lógica: cierra lo vigente y conserva su historial (se puede reactivar).
const motivoBaja = ref("");
const dandoDeBaja = ref(false);
async function darDeBaja(): Promise<void> {
  const nombre = [form.nombre, form.primer_apellido].filter(Boolean).join(" ");
  if (
    !(await confirmar(`${t("bajas.darDeBaja")}: ${nombre}?`, { peligro: true }))
  ) {
    return;
  }
  dandoDeBaja.value = true;
  error.value = null;
  try {
    await api.delete(`${base}/miembros/${props.miembro.id}`, {
      data: { motivo: motivoBaja.value.trim() || null },
    });
    emit("guardado", props.miembro);
    emit("cerrar");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    dandoDeBaja.value = false;
  }
}

async function guardar(): Promise<void> {
  guardando.value = true;
  error.value = null;
  try {
    const { data } = await api.put<{ data: MiembroEditable }>(
      `${base}/miembros/${props.miembro.id}`,
      {
        nombre: form.nombre,
        segundo_nombre: form.segundo_nombre || null,
        primer_apellido: form.primer_apellido || null,
        segundo_apellido: form.segundo_apellido || null,
        email: form.email || null,
        activo: form.activo,
        es_facturable: form.es_facturable,
        archivado: form.archivado,
      },
    );
    emit("guardado", data.data);
    emit("cerrar");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <PanelLateral
    :abierto="true"
    :titulo="$t('miembros.editar.titulo')"
    @cerrar="emit('cerrar')"
  >
    <form class="space-y-4" @submit.prevent="guardar">
      <div>
        <label class="tu-label" for="en">{{ $t("miembros.nombre") }}</label>
        <input id="en" v-model="form.nombre" class="tu-input" required />
      </div>
      <div>
        <label class="tu-label" for="ep">{{
          $t("miembros.primerApellido")
        }}</label>
        <input id="ep" v-model="form.primer_apellido" class="tu-input" />
      </div>
      <div>
        <label class="tu-label" for="ee">{{ $t("miembros.email") }}</label>
        <input id="ee" v-model="form.email" class="tu-input" type="email" />
      </div>

      <div
        class="border-t pt-4 space-y-3"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <p class="text-sm font-semibold">
          {{ $t("miembros.editar.estado") }}
        </p>
        <label class="flex items-center justify-between gap-3 text-sm">
          <span>
            {{ $t("miembros.editar.activo") }}
            <span
              class="block text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("miembros.editar.activoAyuda") }}</span
            >
          </span>
          <input v-model="form.activo" type="checkbox" class="h-5 w-5" />
        </label>
        <label class="flex items-center justify-between gap-3 text-sm">
          <span>
            {{ $t("miembros.editar.facturable") }}
            <span
              class="block text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("miembros.editar.facturableAyuda") }}</span
            >
          </span>
          <input v-model="form.es_facturable" type="checkbox" class="h-5 w-5" />
        </label>
        <label class="flex items-center justify-between gap-3 text-sm">
          <span>
            {{ $t("miembros.editar.archivado") }}
            <span
              class="block text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("miembros.editar.archivadoAyuda") }}</span
            >
          </span>
          <input v-model="form.archivado" type="checkbox" class="h-5 w-5" />
        </label>
      </div>

      <p v-if="error" class="text-sm" style="color: var(--error)">
        {{ error }}
      </p>
      <button
        class="tu-btn tu-btn-primario w-full"
        type="submit"
        :disabled="guardando"
      >
        {{ guardando ? $t("comun.guardar") + "…" : $t("comun.guardar") }}
      </button>

      <div
        v-if="sesion.puede('miembros.eliminar')"
        class="border-t pt-4 space-y-2"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <p class="text-sm font-semibold">{{ $t("bajas.darDeBaja") }}</p>
        <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("bajas.ayudaMiembro") }}
        </p>
        <input
          v-model="motivoBaja"
          class="tu-input"
          maxlength="500"
          :placeholder="$t('bajas.motivo')"
        />
        <button
          type="button"
          class="tu-btn tu-btn-fantasma w-full"
          style="color: var(--error)"
          :disabled="dandoDeBaja"
          @click="darDeBaja"
        >
          {{ $t("bajas.darDeBaja") }}
        </button>
      </div>
    </form>
  </PanelLateral>
</template>
