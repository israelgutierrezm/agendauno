<script setup lang="ts">
import { reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import CampoCelular from "@/components/CampoCelular.vue";
import CamposDatosPersonales from "@/components/CamposDatosPersonales.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { useSesionTenantStore } from "@/stores/sesionTenant";

export interface MiembroEditable {
  id: string;
  nombre: string;
  segundo_nombre: string | null;
  primer_apellido: string | null;
  segundo_apellido: string | null;
  email: string | null;
  // Sin el dato (quien abre no lo tiene), el celular no se edita aquí.
  celular?: string | null;
  activo: boolean;
  es_facturable: boolean;
  archivado: boolean;
  fecha_nacimiento?: string | null;
  genero?: string | null;
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
  celular: props.miembro.celular ?? "",
  fecha_nacimiento: props.miembro.fecha_nacimiento ?? "",
  genero: props.miembro.genero ?? "",
  activo: props.miembro.activo,
  es_facturable: props.miembro.es_facturable,
  archivado: props.miembro.archivado,
});
// El patrón común de los formularios: errores junto al campo, no se cierra mientras
// guarda, pregunta antes de descartar cambios y confirma lo guardado.
const abierto = ref(true);
watch(abierto, (v) => {
  if (!v) emit("cerrar");
});
const {
  guardando,
  errores,
  error,
  empezar,
  cerrar,
  marcar,
  guardar: guardarForm,
} = useEditorFormulario({
  abierto,
  valor: () => ({ ...form }),
  campos: [
    "nombre",
    "primer_apellido",
    "email",
    "celular",
    "fecha_nacimiento",
    "genero",
  ],
  formulario: "#form-editar-miembro",
});
empezar();

// Baja lógica: cierra lo vigente y conserva su historial (se puede reactivar).
const motivoBaja = ref("");
const dandoDeBaja = ref(false);
async function darDeBaja(): Promise<void> {
  const nombre = [form.nombre, form.primer_apellido].filter(Boolean).join(" ");
  if (
    !(await confirmar(t("bajas.confirmar", { nombre }), {
      peligro: true,
      aceptar: t("bajas.darDeBajaMiembro"),
    }))
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
  const nombre = [form.nombre, form.primer_apellido].filter(Boolean).join(" ");
  const respuesta = await guardarForm(
    () =>
      api.put<{ data: MiembroEditable }>(
        `${base}/miembros/${props.miembro.id}`,
        {
          nombre: form.nombre,
          segundo_nombre: form.segundo_nombre || null,
          primer_apellido: form.primer_apellido || null,
          segundo_apellido: form.segundo_apellido || null,
          email: form.email || null,
          // Solo si lo cambió: uno guardado sin lada se queda como está (ADR 0103).
          ...(props.miembro.celular !== undefined &&
          form.celular !== (props.miembro.celular ?? "")
            ? { celular: form.celular || null }
            : {}),
          fecha_nacimiento: form.fecha_nacimiento || null,
          genero: form.genero || null,
          activo: form.activo,
          es_facturable: form.es_facturable,
          archivado: form.archivado,
        },
      ),
    t("miembros.editar.guardado", { nombre }),
  );
  if (respuesta !== undefined) {
    emit("guardado", respuesta.data.data);
    // Ya guardado: se cierra sin preguntar.
    abierto.value = false;
  }
}
</script>

<template>
  <PanelLateral
    :abierto="true"
    :titulo="$t('miembros.editar.titulo')"
    :ocupado="guardando || dandoDeBaja"
    @cerrar="cerrar"
  >
    <form id="form-editar-miembro" class="space-y-4" @submit.prevent="guardar">
      <AvisoTono v-if="error" tono="peligro" role="alert">
        {{ error }}
      </AvisoTono>
      <div>
        <label class="tu-label" for="en">{{ $t("miembros.nombre") }}</label>
        <input
          id="en"
          v-bind="marcar('nombre', 'en-error')"
          v-model="form.nombre"
          class="tu-input"
          required
        />
        <span v-if="errores.nombre" id="en-error" class="tu-error-campo">{{
          errores.nombre
        }}</span>
      </div>
      <div>
        <label class="tu-label" for="ep">{{
          $t("miembros.primerApellido")
        }}</label>
        <input
          id="ep"
          v-bind="marcar('primer_apellido', 'ep-error')"
          v-model="form.primer_apellido"
          class="tu-input"
        />
        <span
          v-if="errores.primer_apellido"
          id="ep-error"
          class="tu-error-campo"
          >{{ errores.primer_apellido }}</span
        >
      </div>
      <div>
        <label class="tu-label" for="ee">{{ $t("miembros.email") }}</label>
        <input
          id="ee"
          v-bind="marcar('email', 'ee-error')"
          v-model="form.email"
          class="tu-input"
          type="email"
        />
        <span v-if="errores.email" id="ee-error" class="tu-error-campo">{{
          errores.email
        }}</span>
      </div>
      <div v-if="miembro.celular !== undefined">
        <label class="tu-label" for="ec">{{ $t("miembros.celular") }}</label>
        <CampoCelular
          id="ec"
          v-model="form.celular"
          maxlength="30"
          autocomplete="off"
          data-prueba="celular-miembro"
          v-bind="marcar('celular', 'ec-error')"
        />
        <span v-if="errores.celular" id="ec-error" class="tu-error-campo">{{
          errores.celular
        }}</span>
      </div>
      <CamposDatosPersonales
        id="em"
        v-model:fecha="form.fecha_nacimiento"
        v-model:genero="form.genero"
      />

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
          class="tu-btn tu-btn-peligro w-full"
          :disabled="dandoDeBaja"
          @click="darDeBaja"
        >
          {{ $t("bajas.darDeBajaMiembro") }}
        </button>
      </div>
    </form>
    <!-- Guardar va en el pie fijo: en el teléfono queda a la vista sin tapar los
         campos ni el teclado (ADR 0166). La baja sigue aparte, en el cuerpo. -->
    <template #pie>
      <div class="flex justify-end gap-2">
        <button
          class="tu-btn tu-btn-secundario"
          type="button"
          :disabled="guardando || dandoDeBaja"
          @click="cerrar"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <button
          class="tu-btn tu-btn-primario"
          type="submit"
          form="form-editar-miembro"
          :disabled="guardando || dandoDeBaja"
          data-prueba="guardar-miembro"
        >
          {{ guardando ? $t("comun.guardar") + "…" : $t("comun.guardar") }}
        </button>
      </div>
    </template>
  </PanelLateral>
</template>
