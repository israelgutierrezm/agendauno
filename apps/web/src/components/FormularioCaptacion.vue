<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import { trackEvent } from "@/lib/analytics";
import { api, camposConError, mensajeDeError } from "@/lib/api";
import { leerAtribucion, type Ambito } from "@/lib/atribucion";
import { camposDeMedicion } from "@/lib/etiquetas";
import type { FormularioPublico } from "@/lib/captacion";
import { tokenRecaptcha } from "@/lib/recaptcha";

/**
 * El formulario de captación que llena quien todavía no es cliente (ADR 0117): en la
 * página del negocio («Pedir acceso»), por enlace o QR, o embebido en otro sitio. Pide
 * nombre, correo, el teléfono si el negocio lo quiere, sus preguntas y la aceptación del
 * aviso de privacidad del negocio. Lo que se envía llega a Prospectos; no crea cuenta.
 *
 * `interes`: lo que tenía elegido (una clase, un plan…), con su texto para mostrarlo.
 */
const props = withDefaults(
  defineProps<{
    slug: string;
    formulario?: string | null;
    interes?: {
      sesion?: string | null;
      producto?: string | null;
      actividad?: string | null;
      sucursal?: string | null;
      etiqueta?: string | null;
    } | null;
    compacto?: boolean;
  }>(),
  { formulario: null, interes: null, compacto: false },
);
const emit = defineEmits<{ enviado: []; cargado: [FormularioPublico] }>();

const { t } = useI18n();
const definicion = ref<FormularioPublico | null>(null);
const cargando = ref(true);
const noDisponible = ref(false);
const enviando = ref(false);
const mensaje = ref<string | null>(null);
const error = ref<string | null>(null);
const errores = ref<string[]>([]);
const datos = reactive({
  nombre: "",
  email: "",
  telefono: "",
  acepta: false,
  respuestas: {} as Record<string, string | boolean>,
});

const base = computed(
  () => `/api/v1/app/${encodeURIComponent(props.slug)}/captacion`,
);

async function cargar(): Promise<void> {
  cargando.value = true;
  noDisponible.value = false;
  try {
    const { data } = await api.get<{ data: FormularioPublico }>(
      props.formulario
        ? `${base.value}/formularios/${encodeURIComponent(props.formulario)}`
        : base.value,
    );
    definicion.value = data.data;
    for (const campo of data.data.campos) {
      if (campo.id && !(campo.id in datos.respuestas)) {
        datos.respuestas[campo.id] = campo.tipo === "booleano" ? false : "";
      }
    }
    emit("cargado", data.data);
  } catch {
    noDisponible.value = true;
  } finally {
    cargando.value = false;
  }
}

// De dónde llegó (ADR 0142): la campaña del último toque en este negocio, anotada al
// navegar (no solo la de esta dirección), sin datos personales.
const ambito = computed<Ambito>(() => `negocio:${props.slug.toLowerCase()}`);
function fuente(): string | null {
  const toque = leerAtribucion(ambito.value).ultimo_toque;
  const partes = [toque?.fuente, toque?.campana].filter(
    (p): p is string => !!p,
  );
  return partes.length > 0
    ? partes.join(" / ").slice(0, 120)
    : (toque?.referente ?? null);
}

const puedeEnviar = computed(
  () =>
    !!definicion.value?.aviso &&
    datos.acepta &&
    datos.nombre.trim() !== "" &&
    datos.email.trim() !== "" &&
    !enviando.value,
);

async function enviar(): Promise<void> {
  if (!definicion.value || !puedeEnviar.value) {
    return;
  }
  enviando.value = true;
  error.value = null;
  errores.value = [];
  try {
    const respuestas: Record<string, string | boolean> = {};
    for (const [clave, valor] of Object.entries(datos.respuestas)) {
      if (valor !== "") {
        respuestas[clave] = valor;
      }
    }
    const { data } = await api.post<{ data: { mensaje: string } }>(
      `${base.value}/formularios/${encodeURIComponent(definicion.value.id)}/envios`,
      {
        nombre: datos.nombre,
        email: datos.email,
        telefono: datos.telefono || null,
        respuestas,
        interes: props.interes
          ? {
              sesion: props.interes.sesion ?? null,
              producto: props.interes.producto ?? null,
              actividad: props.interes.actividad ?? null,
              sucursal: props.interes.sucursal ?? null,
            }
          : null,
        fuente: fuente(),
        ...(await camposDeMedicion(ambito.value)),
        acepta_aviso: datos.acepta,
        aviso_version: definicion.value.aviso?.version ?? null,
        recaptcha_token: await tokenRecaptcha("captacion"),
      },
    );
    mensaje.value = data.data.mensaje;
    trackEvent("lead_submitted", { form: definicion.value.id });
    emit("enviado");
  } catch (e) {
    errores.value = camposConError(e);
    error.value = mensajeDeError(e);
  } finally {
    enviando.value = false;
  }
}

function otraVez(): void {
  mensaje.value = null;
  datos.nombre = "";
  datos.email = "";
  datos.telefono = "";
  datos.acepta = false;
  for (const clave of Object.keys(datos.respuestas)) {
    datos.respuestas[clave] =
      typeof datos.respuestas[clave] === "boolean" ? false : "";
  }
}

function conError(campo: string): boolean {
  return errores.value.includes(campo);
}

watch(() => [props.slug, props.formulario], cargar);
onMounted(cargar);
</script>

<template>
  <div class="formulario-captacion" data-prueba="formulario-captacion">
    <p v-if="cargando" class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-else-if="noDisponible || !definicion" class="text-sm" role="status">
      {{ t("captacion.publico.noDisponible") }}
    </p>
    <div v-else-if="mensaje" role="status" aria-live="polite">
      <h2 class="text-lg font-medium">
        {{ t("captacion.publico.listoTitulo") }}
      </h2>
      <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
        {{ mensaje }}
      </p>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma mt-4"
        @click="otraVez"
      >
        {{ t("captacion.publico.otraVez") }}
      </button>
    </div>
    <form v-else class="space-y-4" novalidate @submit.prevent="enviar">
      <div v-if="!compacto">
        <h2 class="text-xl font-medium">{{ definicion.titulo }}</h2>
        <p
          v-if="definicion.descripcion"
          class="mt-1"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ definicion.descripcion }}
        </p>
      </div>
      <p
        v-if="interes?.etiqueta"
        class="text-sm"
        data-prueba="captacion-interes"
      >
        {{ t("captacion.publico.interes", { que: interes.etiqueta }) }}
      </p>

      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block sm:col-span-2">
          <span class="tu-label">{{ t("captacion.publico.nombre") }}</span>
          <input
            v-model="datos.nombre"
            class="tu-input"
            autocomplete="name"
            maxlength="160"
            required
            :aria-invalid="conError('nombre') ? 'true' : undefined"
          />
        </label>
        <label class="block">
          <span class="tu-label">{{ t("captacion.publico.email") }}</span>
          <input
            v-model="datos.email"
            class="tu-input"
            type="email"
            autocomplete="email"
            maxlength="190"
            required
            :aria-invalid="conError('email') ? 'true' : undefined"
          />
        </label>
        <label v-if="definicion.pide_telefono" class="block">
          <span class="tu-label">{{ t("captacion.publico.telefono") }}</span>
          <input
            v-model="datos.telefono"
            class="tu-input"
            type="tel"
            autocomplete="tel"
            maxlength="40"
            :required="definicion.telefono_obligatorio"
            :aria-invalid="conError('telefono') ? 'true' : undefined"
          />
        </label>
      </div>

      <div
        v-for="campo in definicion.campos"
        :key="campo.id ?? campo.etiqueta"
        class="block"
      >
        <template v-if="campo.id">
          <label
            v-if="campo.tipo === 'booleano'"
            class="flex items-start gap-2 text-sm"
          >
            <input
              v-model="datos.respuestas[campo.id]"
              type="checkbox"
              class="mt-1"
            />
            <span>{{ campo.etiqueta }}</span>
          </label>
          <label v-else class="block">
            <span class="tu-label">
              {{ campo.etiqueta }}{{ campo.obligatorio ? " *" : "" }}
            </span>
            <textarea
              v-if="campo.tipo === 'textarea'"
              v-model="datos.respuestas[campo.id] as string"
              class="tu-input"
              rows="3"
              maxlength="1000"
              :required="campo.obligatorio"
              :aria-invalid="
                conError(`respuestas.${campo.id}`) ? 'true' : undefined
              "
            />
            <select
              v-else-if="campo.tipo === 'seleccion'"
              v-model="datos.respuestas[campo.id]"
              class="tu-input"
              :required="campo.obligatorio"
              :aria-invalid="
                conError(`respuestas.${campo.id}`) ? 'true' : undefined
              "
            >
              <option value="">{{ t("captacion.publico.elige") }}</option>
              <option v-for="o in campo.opciones ?? []" :key="o" :value="o">
                {{ o }}
              </option>
            </select>
            <input
              v-else
              v-model="datos.respuestas[campo.id] as string"
              class="tu-input"
              :type="
                campo.tipo === 'numero'
                  ? 'number'
                  : campo.tipo === 'fecha'
                    ? 'date'
                    : 'text'
              "
              maxlength="1000"
              :required="campo.obligatorio"
              :aria-invalid="
                conError(`respuestas.${campo.id}`) ? 'true' : undefined
              "
            />
          </label>
        </template>
      </div>

      <p
        v-if="!definicion.aviso"
        class="text-sm"
        role="status"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ t("captacion.publico.sinAviso") }}
      </p>
      <label v-else class="flex items-start gap-2 text-sm">
        <input v-model="datos.acepta" type="checkbox" class="mt-1" />
        <span>
          {{ t("captacion.publico.aviso") }}
          <RouterLink
            class="tu-enlace"
            :to="{ name: 'aviso-negocio', params: { slug } }"
            target="_blank"
            >{{ t("captacion.publico.avisoEnlace") }}</RouterLink
          >
        </span>
      </label>

      <AvisoTono v-if="error" tono="peligro" role="alert">
        {{ error }}
      </AvisoTono>
      <button
        class="tu-btn tu-btn-primario w-full sm:w-auto"
        type="submit"
        :disabled="!puedeEnviar"
      >
        {{
          enviando
            ? t("captacion.publico.enviando")
            : t("captacion.publico.enviar")
        }}
      </button>
    </form>
  </div>
</template>
