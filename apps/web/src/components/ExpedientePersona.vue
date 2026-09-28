<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import ListaFormularios from "@/components/ListaFormularios.vue";
import { api, mensajeDeError } from "@/lib/api";
import type { FormularioPersona } from "@/lib/formularios";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Expediente de una persona (miembro o instructor): lo que se le ha cargado o ha
 * llenado. Documentos (ver, validar, subir), consentimientos vigentes (firmados o
 * no) y formularios con sus respuestas. El acceso lo decide la API (el de personal
 * es solo para quien administra al equipo).
 */
const props = defineProps<{
  personaId: string;
  // Tipo de la persona, para ofrecer solo los tipos de documento que le aplican.
  tipoPersona?: "miembro" | "instructor";
}>();

interface Documento {
  id: string;
  nombre: string;
  tipo: string | null;
  estado: "pendiente" | "aprobado" | "rechazado";
  motivo: string | null;
  subido_en: string | null;
}
interface Consentimiento {
  id: string;
  titulo: string;
  version: number;
  aceptado_en: string | null;
}
interface Expediente {
  documentos: Documento[];
  consentimientos: Consentimiento[];
  formularios: FormularioPersona[];
}
interface TipoDocumento {
  id: string;
  nombre: string;
  aplica_a: string | null;
  activo: boolean;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeSubir = computed(() => sesion.puede("documentos.subir"));
const puedeValidar = computed(() => sesion.puede("documentos.gestionar"));
const puedeResponder = computed(() => sesion.puede("formularios.responder"));

const expediente = ref<Expediente | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Expediente }>(
      `${base.value}/personas/${props.personaId}/expediente`,
    );
    expediente.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e, t("expediente.error"));
  } finally {
    cargando.value = false;
  }
}
watch(() => props.personaId, cargar, { immediate: true });

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(iso));
}

const COLOR_ESTADO: Record<Documento["estado"], string> = {
  pendiente: "var(--aviso)",
  aprobado: "var(--exito)",
  rechazado: "var(--error)",
};

// ---- Ver (descarga protegida con el token) ----
async function ver(d: Documento): Promise<void> {
  try {
    const { data } = await api.get<Blob>(`${base.value}/documentos/${d.id}`, {
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    window.open(url, "_blank", "noopener");
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (e) {
    toast.error(mensajeDeError(e, t("expediente.error")));
  }
}

async function validar(
  d: Documento,
  estado: "aprobado" | "rechazado",
): Promise<void> {
  try {
    await api.post(`${base.value}/documentos/${d.id}/validar`, { estado });
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e, t("expediente.error")));
  }
}

// ---- Subir ----
const tipos = ref<TipoDocumento[]>([]);
const subiendo = ref(false);
const abiertoSubir = ref(false);
const tipoSel = ref("");
const archivo = ref<File | null>(null);
const selector = ref<HTMLInputElement | null>(null);

const tiposQueAplican = computed(() =>
  tipos.value.filter(
    (tp) =>
      tp.activo &&
      (tp.aplica_a === null ||
        tp.aplica_a === "todos" ||
        tp.aplica_a === (props.tipoPersona ?? "miembro")),
  ),
);

async function abrirSubir(): Promise<void> {
  abiertoSubir.value = true;
  if (tipos.value.length === 0) {
    try {
      const { data } = await api.get<{ data: TipoDocumento[] }>(
        `${base.value}/tipos-documento`,
      );
      tipos.value = data.data;
    } catch {
      tipos.value = [];
    }
  }
}
function alElegir(e: Event): void {
  archivo.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}
async function subir(): Promise<void> {
  if (archivo.value === null) {
    return;
  }
  subiendo.value = true;
  try {
    const datos = new FormData();
    datos.append("persona_id", props.personaId);
    if (tipoSel.value !== "") {
      datos.append("tipo_documento_id", tipoSel.value);
    }
    datos.append("archivo", archivo.value);
    await api.post(`${base.value}/documentos`, datos);
    toast.exito(t("expediente.subido"));
    abiertoSubir.value = false;
    tipoSel.value = "";
    archivo.value = null;
    if (selector.value) {
      selector.value.value = "";
    }
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e, t("expediente.error")));
  } finally {
    subiendo.value = false;
  }
}
</script>

<template>
  <div>
    <p
      v-if="cargando"
      class="ex-seccion text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <p
      v-else-if="error"
      class="ex-seccion text-sm"
      :style="{ color: 'var(--error)' }"
    >
      {{ error }}
    </p>

    <template v-else-if="expediente">
      <!-- Documentos -->
      <section class="ex-seccion">
        <div class="flex items-center justify-between gap-3">
          <h2 class="text-sm font-semibold">
            {{ $t("expediente.documentos") }}
          </h2>
          <button
            v-if="puedeSubir && !abiertoSubir"
            type="button"
            class="tu-btn tu-btn-fantasma px-3 py-1.5 text-sm"
            @click="abrirSubir"
          >
            {{ $t("expediente.subir") }}
          </button>
        </div>

        <!-- Subir documento -->
        <form
          v-if="abiertoSubir"
          class="mt-3 rounded-xl border p-4 space-y-3"
          :style="{
            borderColor: 'var(--borde)',
            background: 'var(--fondo)',
          }"
          @submit.prevent="subir"
        >
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="tu-label" for="ex-tipo">{{
                $t("expediente.tipo")
              }}</label>
              <select id="ex-tipo" v-model="tipoSel" class="tu-input">
                <option value="">{{ $t("expediente.sinTipo") }}</option>
                <option
                  v-for="tp in tiposQueAplican"
                  :key="tp.id"
                  :value="tp.id"
                >
                  {{ tp.nombre }}
                </option>
              </select>
            </div>
            <div>
              <label class="tu-label" for="ex-archivo">{{
                $t("expediente.archivo")
              }}</label>
              <input
                id="ex-archivo"
                ref="selector"
                type="file"
                accept="application/pdf,image/jpeg,image/png"
                class="tu-input py-1.5 text-sm"
                required
                @change="alElegir"
              />
              <p class="tu-hint">{{ $t("expediente.archivoAyuda") }}</p>
            </div>
          </div>
          <div class="flex gap-2">
            <button
              type="submit"
              class="tu-btn tu-btn-primario text-sm"
              :disabled="subiendo || archivo === null"
            >
              {{
                subiendo ? $t("expediente.subiendo") : $t("expediente.subir")
              }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              :disabled="subiendo"
              @click="abiertoSubir = false"
            >
              {{ $t("comun.cancelar") }}
            </button>
          </div>
        </form>

        <p
          v-if="expediente.documentos.length === 0"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("expediente.sinDocumentos") }}
        </p>
        <ul v-else class="mt-1">
          <li v-for="d in expediente.documentos" :key="d.id" class="ex-fila">
            <div class="min-w-0">
              <p class="font-medium truncate">{{ d.nombre }}</p>
              <p
                class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                <span
                  class="inline-flex items-center gap-1.5"
                  :style="{ color: COLOR_ESTADO[d.estado] }"
                >
                  <span
                    class="h-1.5 w-1.5 rounded-full"
                    :style="{ background: COLOR_ESTADO[d.estado] }"
                    aria-hidden="true"
                  />{{ $t(`expediente.estados.${d.estado}`) }}</span
                >
                <span v-if="d.tipo">· {{ d.tipo }}</span>
                <span>· {{ fecha(d.subido_en) }}</span>
                <span v-if="d.motivo">· {{ d.motivo }}</span>
              </p>
            </div>
            <div class="flex items-center gap-3 shrink-0 text-sm">
              <template v-if="puedeValidar && d.estado === 'pendiente'">
                <button
                  type="button"
                  class="tu-enlace"
                  @click="validar(d, 'aprobado')"
                >
                  {{ $t("expediente.aprobar") }}
                </button>
                <button
                  type="button"
                  class="tu-enlace"
                  :style="{ color: 'var(--error)' }"
                  @click="validar(d, 'rechazado')"
                >
                  {{ $t("expediente.rechazar") }}
                </button>
              </template>
              <button type="button" class="tu-enlace" @click="ver(d)">
                {{ $t("expediente.ver") }}
              </button>
            </div>
          </li>
        </ul>
      </section>

      <!-- Consentimientos (de los alumnos; al personal no le aplican) -->
      <section v-if="tipoPersona !== 'instructor'" class="ex-seccion">
        <h2 class="text-sm font-semibold">
          {{ $t("expediente.consentimientos") }}
        </h2>
        <p
          v-if="expediente.consentimientos.length === 0"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("expediente.sinConsentimientos") }}
        </p>
        <ul v-else class="mt-1">
          <li
            v-for="c in expediente.consentimientos"
            :key="c.id"
            class="ex-fila"
          >
            <div class="min-w-0">
              <p class="font-medium truncate">{{ c.titulo }}</p>
              <p
                class="mt-0.5 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("expediente.version", { n: c.version }) }}
              </p>
            </div>
            <span
              v-if="c.aceptado_en"
              class="tu-badge tu-badge-exito shrink-0"
              >{{
                $t("expediente.firmado", { fecha: fecha(c.aceptado_en) })
              }}</span
            >
            <span v-else class="tu-badge tu-badge-aviso shrink-0">{{
              $t("expediente.pendiente")
            }}</span>
          </li>
        </ul>
      </section>

      <!-- Formularios -->
      <section class="ex-seccion">
        <h2 class="text-sm font-semibold">
          {{ $t("expediente.formularios") }}
        </h2>
        <p
          v-if="expediente.formularios.length === 0"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("expediente.sinFormularios") }}
        </p>
        <ListaFormularios
          v-else
          class="mt-1"
          :formularios="expediente.formularios"
          :persona-id="personaId"
          :puede-responder="puedeResponder"
          @guardado="cargar"
        />
      </section>
    </template>
  </div>
</template>

<style scoped>
.ex-seccion {
  padding: 1.25rem;
  border-top: 1px solid var(--borde);
}
.ex-seccion:first-child {
  border-top: 0;
}
.ex-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.ex-fila:first-child {
  border-top: 0;
}
</style>
