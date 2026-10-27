<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { claveSegunModalidad } from "@/lib/menu";
import { type Plan } from "@/lib/planes";
import { queIncluyePlan, reglasDePlan } from "@/lib/ventaDePlan";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Dar un plan de cortesía desde la ficha (ADR 0181): se activa hoy y sin cobro (no
 * queda venta ni pago por cobrar) y siempre con su motivo, que queda en la bitácora.
 * Antes de darlo se ve qué incluye y sus reglas (con permanencia, las condiciones que
 * acepta el alumno), y se confirma.
 */
const props = defineProps<{
  base: string;
  personaId: string;
  nombre: string;
}>();
const abierto = defineModel<boolean>("abierto", { required: true });
const emit = defineEmits<{ hecho: [aviso: string] }>();

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
// En citas son bonos (ADR 0130): la variante «…Citas» si existe.
function tm(
  clave: string,
  valores: Record<string, unknown> = {},
  cuantos?: number,
): string {
  const llave = claveSegunModalidad(clave, sesion.esCitas, te);
  return cuantos === undefined ? t(llave, valores) : t(llave, valores, cuantos);
}

const planes = ref<Plan[]>([]);
const cargando = ref(false);
const errorPlanes = ref<string | null>(null);
const form = ref({ productoId: "", motivo: "", acepta: false });
const editor = useEditorFormulario({
  abierto,
  valor: () => form.value,
  campos: ["producto_id", "motivo"],
  formulario: "#dar-cortesia",
});
const { guardando, errores, error } = editor;

const vendibles = computed(() => planes.value.filter((p) => !p.archivado));
const elegido = computed(
  () => planes.value.find((p) => p.id === form.value.productoId) ?? null,
);
const reglas = computed(() =>
  elegido.value
    ? reglasDePlan(elegido.value, t, {
        variasSedes: (sesion.usuario?.sucursales?.length ?? 0) > 1,
        esCitas: sesion.esCitas,
      })
    : [],
);
const pideCondiciones = computed(
  () => (elegido.value?.permanencia_meses ?? 0) > 0,
);
const listo = computed(
  () =>
    elegido.value !== null &&
    form.value.motivo.trim().length >= 3 &&
    (!pideCondiciones.value || form.value.acepta),
);
watch(
  () => form.value.productoId,
  () => {
    form.value.acepta = false;
  },
);

async function cargarPlanes(): Promise<void> {
  cargando.value = true;
  errorPlanes.value = null;
  try {
    const { data } = await api.get<{ data: Plan[] }>(`${props.base}/productos`);
    planes.value = data.data;
  } catch (e) {
    errorPlanes.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

watch(
  abierto,
  (si) => {
    if (!si) {
      return;
    }
    form.value = { productoId: "", motivo: "", acepta: false };
    editor.empezar();
    if (planes.value.length === 0) {
      void cargarPlanes();
    }
  },
  { immediate: true },
);

async function dar(): Promise<void> {
  const plan = elegido.value;
  if (plan === null || !listo.value) {
    return;
  }
  if (
    !(await confirmar(
      t("confirmaciones.cortesia", {
        plan: plan.nombre,
        persona: props.nombre,
      }),
      { aceptar: t("operacion.ficha.cortesia.confirmar") },
    ))
  ) {
    return;
  }
  const respuesta = await editor.guardar(() =>
    api.post(`${props.base}/acuerdos`, {
      persona_id: props.personaId,
      producto_id: plan.id,
      motivo: form.value.motivo.trim(),
      ...(pideCondiciones.value ? { acepta_condiciones: true } : {}),
    }),
  );
  if (respuesta === undefined) {
    return;
  }
  abierto.value = false;
  emit(
    "hecho",
    t("operacion.ficha.cortesia.hecho", {
      plan: plan.nombre,
      persona: props.nombre,
    }),
  );
}
</script>

<template>
  <ModalDialogo
    :abierto="abierto"
    :titulo="tm('operacion.ficha.cortesia.titulo')"
    tam="md"
    :descripcion="t('operacion.ficha.cortesia.ayuda')"
    :ocupado="guardando"
    @cerrar="editor.cerrar"
  >
    <form
      id="dar-cortesia"
      class="space-y-4"
      data-prueba="dar-cortesia"
      @submit.prevent="dar"
    >
      <AvisoTono v-if="error || errorPlanes" tono="peligro" role="alert">
        {{ error ?? errorPlanes }}
      </AvisoTono>
      <p
        v-if="cargando"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ t("comun.cargando") }}
      </p>
      <p
        v-else-if="!errorPlanes && vendibles.length === 0"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ tm("operacion.ficha.cortesia.sinPlanes") }}
      </p>
      <template v-else-if="vendibles.length > 0">
        <div>
          <label class="tu-label" for="dc-plan">{{
            tm("operacion.ficha.cortesia.plan")
          }}</label>
          <select
            id="dc-plan"
            v-bind="editor.marcar('producto_id', 'dc-plan-error')"
            v-model="form.productoId"
            class="tu-input"
            required
          >
            <option value="" disabled>
              {{ tm("operacion.ficha.cortesia.elegir") }}
            </option>
            <option v-for="p in vendibles" :key="p.id" :value="p.id">
              {{ p.nombre }}
            </option>
          </select>
          <span
            v-if="errores.producto_id"
            id="dc-plan-error"
            class="tu-error-campo"
            >{{ errores.producto_id }}</span
          >
          <!-- Lo que se le da: qué incluye, dónde vale y sus reglas. -->
          <div
            v-if="elegido"
            class="mt-2 space-y-0.5 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            data-prueba="detalle-cortesia"
          >
            <p>{{ queIncluyePlan(elegido, tm) }}</p>
            <p v-for="r in reglas" :key="r">{{ r }}</p>
          </div>
        </div>
        <!-- Permanencia (B04): las condiciones que acepta el alumno. -->
        <div
          v-if="pideCondiciones && elegido?.condiciones"
          class="space-y-2 rounded-lg p-3 text-sm"
          :style="{ background: 'var(--superficie-2)' }"
          data-prueba="condiciones-cortesia"
        >
          <p>{{ elegido.condiciones }}</p>
          <label class="flex items-start gap-2">
            <input v-model="form.acepta" type="checkbox" class="mt-1" />
            {{ t("ventas.vender.aceptaCondiciones") }}
          </label>
        </div>
        <div>
          <label class="tu-label" for="dc-motivo">{{
            t("operacion.ficha.cortesia.motivo")
          }}</label>
          <input
            id="dc-motivo"
            v-bind="editor.marcar('motivo', 'dc-motivo-error')"
            v-model="form.motivo"
            class="tu-input"
            minlength="3"
            maxlength="300"
            required
            :placeholder="tm('operacion.ficha.cortesia.motivoPh')"
          />
          <span
            v-if="errores.motivo"
            id="dc-motivo-error"
            class="tu-error-campo"
            >{{ errores.motivo }}</span
          >
          <span v-else class="tu-hint">{{
            t("operacion.ficha.cortesia.motivoAyuda")
          }}</span>
        </div>
      </template>
    </form>
    <template #pie>
      <button
        type="button"
        class="tu-btn tu-btn-secundario"
        :disabled="guardando"
        @click="editor.cerrar"
      >
        {{ t("comun.cancelar") }}
      </button>
      <button
        type="submit"
        form="dar-cortesia"
        class="tu-btn tu-btn-primario"
        :disabled="guardando || !listo"
        :aria-busy="guardando || undefined"
        data-prueba="confirmar-cortesia"
      >
        {{ t("operacion.ficha.cortesia.confirmar") }}
      </button>
    </template>
  </ModalDialogo>
</template>
