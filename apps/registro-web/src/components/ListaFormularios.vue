<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import type {
  CampoFormulario,
  FormularioPersona,
  ValorCampo,
} from "@/lib/formularios";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Los formularios de una persona: ver lo que respondió y llenarlos o corregirlos
 * aquí mismo, uno a la vez. Lo usan el expediente (personal) y "Mi cuenta" (alumno).
 */
const props = defineProps<{
  formularios: FormularioPersona[];
  personaId: string;
  puedeResponder: boolean;
}>();
const emit = defineEmits<{ guardado: [] }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

function fecha(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(iso));
}

const abiertos = ref<Set<string>>(new Set());
function alternar(id: string): void {
  const copia = new Set(abiertos.value);
  if (copia.has(id)) {
    copia.delete(id);
  } else {
    copia.add(id);
  }
  abiertos.value = copia;
}

function valor(c: CampoFormulario): string {
  if (c.valor === null || c.valor === "") {
    return t("expediente.sinValor");
  }
  if (c.tipo === "booleano") {
    return c.valor === true ? t("expediente.si") : t("expediente.no");
  }
  if (c.tipo === "fecha" && typeof c.valor === "string") {
    return fecha(`${c.valor}T12:00:00`);
  }
  return String(c.valor);
}

// Formulario que se está llenando (uno a la vez) y sus valores por campo.
const llenando = ref<string | null>(null);
// Texto de cada campo (los inputs trabajan con texto) y las casillas sí/no.
const borrador = ref<Record<string, string>>({});
const casillas = ref<Record<string, boolean>>({});
const guardando = ref(false);

function llenar(f: FormularioPersona): void {
  llenando.value = f.id;
  borrador.value = Object.fromEntries(
    f.campos
      .filter((c) => c.tipo !== "booleano")
      .map((c) => [c.id, c.valor === null ? "" : String(c.valor)]),
  );
  casillas.value = Object.fromEntries(
    f.campos
      .filter((c) => c.tipo === "booleano")
      .map((c) => [c.id, c.valor === true]),
  );
}

async function guardar(f: FormularioPersona): Promise<void> {
  guardando.value = true;
  try {
    const valores: Record<string, ValorCampo> = {};
    for (const c of f.campos) {
      if (c.tipo === "booleano") {
        valores[c.id] = casillas.value[c.id] === true;
        continue;
      }
      const v = borrador.value[c.id] ?? "";
      if (v !== "") {
        valores[c.id] = c.tipo === "numero" ? Number(v) : v;
      }
    }
    await api.post(`${base.value}/formularios/${f.id}/respuestas`, {
      persona_id: props.personaId,
      valores,
    });
    toast.exito(t("expediente.formularioGuardado"));
    llenando.value = null;
    abiertos.value = new Set(abiertos.value).add(f.id);
    emit("guardado");
  } catch (e) {
    toast.error(mensajeDeError(e, t("expediente.error")));
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <ul>
    <li v-for="f in formularios" :key="f.id" class="lf-fila">
      <div class="flex w-full items-center justify-between gap-3">
        <div class="min-w-0">
          <p class="font-medium truncate">{{ f.nombre }}</p>
          <p
            class="mt-0.5 text-xs"
            :style="{
              color: f.respondido_en ? 'var(--texto-suave)' : 'var(--aviso)',
            }"
          >
            {{
              f.respondido_en
                ? $t("expediente.respondido", {
                    fecha: fecha(f.respondido_en),
                  })
                : $t("expediente.sinResponder")
            }}
          </p>
        </div>
        <div
          v-if="llenando !== f.id"
          class="flex items-center gap-3 shrink-0 text-sm"
        >
          <button
            v-if="f.respondido_en"
            type="button"
            class="tu-enlace"
            :aria-expanded="abiertos.has(f.id)"
            @click="alternar(f.id)"
          >
            {{
              abiertos.has(f.id)
                ? $t("expediente.ocultarRespuestas")
                : $t("expediente.verRespuestas")
            }}
          </button>
          <button
            v-if="puedeResponder && f.campos.length > 0"
            type="button"
            class="tu-btn tu-btn-fantasma px-3 py-1.5 text-sm"
            @click="llenar(f)"
          >
            {{
              f.respondido_en
                ? $t("expediente.editarRespuestas")
                : $t("expediente.llenar")
            }}
          </button>
        </div>
      </div>

      <!-- Llenar / editar respuestas -->
      <form
        v-if="llenando === f.id"
        class="mt-3 w-full rounded-xl border p-4 space-y-4"
        :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
        @submit.prevent="guardar(f)"
      >
        <p
          v-if="f.descripcion"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ f.descripcion }}
        </p>
        <div v-for="c in f.campos" :key="c.id">
          <label
            v-if="c.tipo === 'booleano'"
            class="flex items-center gap-2 text-sm"
          >
            <input v-model="casillas[c.id]" type="checkbox" class="h-4 w-4" />
            {{ c.etiqueta }}
          </label>
          <template v-else>
            <label class="tu-label" :for="`lf-c-${c.id}`"
              >{{ c.etiqueta
              }}<span v-if="c.obligatorio" aria-hidden="true"> *</span></label
            >
            <textarea
              v-if="c.tipo === 'textarea'"
              :id="`lf-c-${c.id}`"
              v-model="borrador[c.id]"
              class="tu-input"
              rows="3"
              :required="c.obligatorio"
            />
            <select
              v-else-if="c.tipo === 'seleccion'"
              :id="`lf-c-${c.id}`"
              v-model="borrador[c.id]"
              class="tu-input"
              :required="c.obligatorio"
            >
              <option value="">{{ $t("expediente.elegir") }}</option>
              <option v-for="o in c.opciones ?? []" :key="o" :value="o">
                {{ o }}
              </option>
            </select>
            <input
              v-else
              :id="`lf-c-${c.id}`"
              v-model="borrador[c.id]"
              class="tu-input"
              :type="
                c.tipo === 'numero'
                  ? 'number'
                  : c.tipo === 'fecha'
                    ? 'date'
                    : 'text'
              "
              :required="c.obligatorio"
            />
          </template>
        </div>
        <div class="flex gap-2">
          <button
            type="submit"
            class="tu-btn tu-btn-primario text-sm"
            :disabled="guardando"
          >
            {{ $t("expediente.guardarRespuestas") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma text-sm"
            :disabled="guardando"
            @click="llenando = null"
          >
            {{ $t("comun.cancelar") }}
          </button>
        </div>
      </form>

      <dl
        v-else-if="abiertos.has(f.id)"
        class="mt-3 w-full rounded-xl border px-4 text-sm divide-y divide-[var(--borde)]"
        :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
      >
        <div
          v-for="c in f.campos"
          :key="c.id"
          class="flex items-baseline justify-between gap-4 py-2"
        >
          <dt :style="{ color: 'var(--texto-suave)' }">{{ c.etiqueta }}</dt>
          <dd class="text-right font-medium">{{ valor(c) }}</dd>
        </div>
      </dl>
    </li>
  </ul>
</template>

<style scoped>
.lf-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.lf-fila:first-child {
  border-top: 0;
}
</style>
