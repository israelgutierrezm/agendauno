<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { formatoFechaHora, type DestinoMedicion } from "@/lib/medicion";
import { useToastStore } from "@/stores/toast";

/**
 * Un destino de medición (ADR 0143): GA4 o Meta, del SaaS (superadmin) o de un negocio
 * (Integraciones). Su descripción, sus campos (el secreto se escribe y no se vuelve a
 * mostrar), su estado, el último envío o error, encenderlo, probar la conexión,
 * desconectarlo y la guía para configurarlo.
 */
const props = withDefaults(
  defineProps<{
    destino: DestinoMedicion;
    negocio?: boolean;
    /** Ofrecer los eventos adicionales (asistencia, renovación): los del negocio. */
    extras?: boolean;
    alGuardar: (datos: Record<string, unknown>) => Promise<DestinoMedicion>;
    alProbar: () => Promise<{ ok: boolean; mensajes: string[] }>;
    alDesconectar: () => Promise<DestinoMedicion>;
  }>(),
  { negocio: false, extras: false },
);
const emit = defineEmits<{ cambio: [DestinoMedicion] }>();

const { t, tm, rt } = useI18n();
const toast = useToastStore();

const esMeta = computed(() => props.destino.proveedor === "meta");
const identificador = ref("");
const secreto = ref("");
const codigoPrueba = ref("");
const activo = ref(false);
const avisoConfirmado = ref(false);
const eventosExtra = ref<string[]>([]);
const guardando = ref(false);
const probando = ref(false);
const prueba = ref<{ ok: boolean; mensajes: string[] } | null>(null);
const errores = ref<Record<string, string>>({});

watch(
  () => props.destino,
  (d) => {
    identificador.value = d.identificador ?? "";
    secreto.value = "";
    codigoPrueba.value = d.codigo_prueba ?? "";
    activo.value = d.activo;
    avisoConfirmado.value = d.aviso_confirmado_en !== null;
    eventosExtra.value = [...(d.eventos_extra ?? [])];
  },
  { immediate: true },
);

const TONOS: Record<DestinoMedicion["estado"], string> = {
  apagado: "var(--texto-suave)",
  activo: "var(--exito)",
  con_error: "var(--error)",
};
const configurado = computed(
  () =>
    props.destino.identificador !== null || props.destino.secreto_configurado,
);
const guia = computed(() =>
  (
    tm(esMeta.value ? "medicion.guiaMeta" : "medicion.guiaGa4") as unknown[]
  ).map((paso) => rt(paso as Parameters<typeof rt>[0])),
);
const descripcion = computed(() =>
  esMeta.value && props.negocio
    ? t("medicion.destinos.meta.descripcionNegocio")
    : t(`medicion.destinos.${props.destino.proveedor}.descripcion`),
);

async function guardar(): Promise<void> {
  guardando.value = true;
  errores.value = {};
  try {
    const d = await props.alGuardar({
      identificador: identificador.value.trim() || null,
      ...(secreto.value.trim() !== "" ? { secreto: secreto.value.trim() } : {}),
      ...(esMeta.value
        ? { codigo_prueba: codigoPrueba.value.trim() || null }
        : {}),
      activo: activo.value,
      aviso_confirmado: avisoConfirmado.value,
      ...(props.extras ? { eventos_extra: eventosExtra.value } : {}),
    });
    emit("cambio", d);
    toast.exito(t("medicion.destinos.guardado"));
  } catch (e) {
    const datos = (
      e as {
        response?: { data?: { meta?: { errors?: Record<string, string[]> } } };
      }
    ).response?.data?.meta?.errors;
    errores.value = Object.fromEntries(
      Object.entries(datos ?? {}).map(([campo, m]) => [campo, m[0] ?? ""]),
    );
    if (Object.keys(errores.value).length === 0) {
      toast.error(mensajeDeError(e));
    }
  } finally {
    guardando.value = false;
  }
}

async function probar(): Promise<void> {
  probando.value = true;
  prueba.value = null;
  try {
    prueba.value = await props.alProbar();
  } catch (e) {
    prueba.value = { ok: false, mensajes: [mensajeDeError(e)] };
  } finally {
    probando.value = false;
  }
}

async function desconectar(): Promise<void> {
  const nombre = t(`medicion.destinos.${props.destino.proveedor}.nombre`);
  if (
    !(await confirmar(
      t("medicion.destinos.confirmarDesconectar", { destino: nombre }),
    ))
  ) {
    return;
  }
  try {
    emit("cambio", await props.alDesconectar());
    prueba.value = null;
    toast.exito(t("medicion.destinos.desconectado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}
</script>

<template>
  <article
    class="tu-card p-4 sm:p-5"
    :data-destino="destino.proveedor"
    data-prueba="destino-medicion"
  >
    <header class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="font-medium">
        {{ t(`medicion.destinos.${destino.proveedor}.nombre`) }}
      </h3>
      <span
        class="tu-estado text-sm"
        :style="{ '--tono': TONOS[destino.estado] }"
        data-prueba="estado-destino"
      >
        {{ t(`medicion.destinos.estado.${destino.estado}`) }}
      </span>
    </header>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ descripcion }}
    </p>

    <form class="mt-4 space-y-3" @submit.prevent="guardar">
      <label class="block">
        <span class="tu-label">{{
          t(`medicion.destinos.${destino.proveedor}.identificador`)
        }}</span>
        <input
          v-model="identificador"
          class="tu-input mt-1 w-full"
          autocomplete="off"
          spellcheck="false"
          data-prueba="identificador-destino"
        />
        <small
          v-if="errores.identificador"
          :style="{ color: 'var(--error)' }"
          >{{ errores.identificador }}</small
        >
        <small v-else :style="{ color: 'var(--texto-suave)' }">{{
          t(`medicion.destinos.${destino.proveedor}.ayudaIdentificador`)
        }}</small>
      </label>
      <label class="block">
        <span class="tu-label">{{
          t(`medicion.destinos.${destino.proveedor}.secreto`)
        }}</span>
        <input
          v-model="secreto"
          type="password"
          class="tu-input mt-1 w-full"
          autocomplete="new-password"
          spellcheck="false"
          :placeholder="
            destino.secreto_configurado
              ? t('medicion.destinos.secretoGuardado')
              : ''
          "
          data-prueba="secreto-destino"
        />
        <small v-if="errores.secreto" :style="{ color: 'var(--error)' }">{{
          errores.secreto
        }}</small>
        <small v-else :style="{ color: 'var(--texto-suave)' }">{{
          t(`medicion.destinos.${destino.proveedor}.ayudaSecreto`)
        }}</small>
      </label>
      <label v-if="esMeta" class="block">
        <span class="tu-label">{{
          t("medicion.destinos.meta.codigoPrueba")
        }}</span>
        <input
          v-model="codigoPrueba"
          class="tu-input mt-1 w-full"
          autocomplete="off"
          spellcheck="false"
          data-prueba="codigo-prueba-destino"
        />
        <small :style="{ color: 'var(--texto-suave)' }">{{
          t("medicion.destinos.meta.ayudaCodigoPrueba")
        }}</small>
      </label>
      <label class="flex items-start gap-2 text-sm">
        <input
          v-model="avisoConfirmado"
          type="checkbox"
          class="mt-1"
          data-prueba="aviso-destino"
        />
        <span>{{ t("medicion.destinos.aviso") }}</span>
      </label>
      <small
        v-if="errores.aviso_confirmado"
        class="block"
        :style="{ color: 'var(--error)' }"
        >{{ errores.aviso_confirmado }}</small
      >
      <fieldset v-if="extras" class="space-y-1 text-sm">
        <legend class="tu-label">{{ t("medicion.negocio.extras") }}</legend>
        <p :style="{ color: 'var(--texto-suave)' }">
          {{ t("medicion.negocio.extrasAyuda") }}
        </p>
        <label
          v-for="extra in ['asistencia', 'renovacion']"
          :key="extra"
          class="flex items-center gap-2"
        >
          <input
            v-model="eventosExtra"
            type="checkbox"
            :value="extra"
            :data-prueba="`extra-${extra}`"
          />
          <span>{{ t(`medicion.negocio.extra.${extra}`) }}</span>
        </label>
      </fieldset>
      <label class="flex items-center gap-2 text-sm">
        <input v-model="activo" type="checkbox" data-prueba="activo-destino" />
        <span>{{ t("medicion.destinos.encendido") }}</span>
      </label>
      <small
        v-if="errores.activo"
        class="block"
        :style="{ color: 'var(--error)' }"
        >{{ errores.activo }}</small
      >

      <div class="flex flex-wrap gap-2 pt-1">
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="guardando"
          data-prueba="guardar-destino"
        >
          {{ t("medicion.destinos.guardar") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="probando || !destino.secreto_configurado"
          data-prueba="probar-destino"
          @click="probar"
        >
          {{ t("medicion.destinos.probar") }}
        </button>
        <button
          v-if="configurado"
          type="button"
          class="tu-btn tu-btn-secundario"
          data-prueba="desconectar-destino"
          @click="desconectar"
        >
          {{ t("medicion.destinos.desconectar") }}
        </button>
      </div>
    </form>

    <ul
      v-if="prueba"
      class="mt-3 space-y-1 text-sm"
      data-prueba="resultado-prueba"
    >
      <li
        v-for="(m, i) in prueba.mensajes"
        :key="i"
        class="tu-estado"
        :style="{ '--tono': prueba.ok ? 'var(--exito)' : 'var(--error)' }"
      >
        {{ m }}
      </li>
    </ul>

    <div
      class="mt-3 space-y-1 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      <p>
        {{
          destino.ultimo_envio_en
            ? t("medicion.destinos.ultimoEnvio", {
                fecha: formatoFechaHora(destino.ultimo_envio_en),
              })
            : t("medicion.destinos.sinEnvios")
        }}
      </p>
      <p v-if="destino.ultimo_error && destino.ultimo_error_en">
        {{
          t("medicion.destinos.ultimoError", {
            fecha: formatoFechaHora(destino.ultimo_error_en),
            error: destino.ultimo_error,
          })
        }}
      </p>
    </div>

    <details class="mt-3 text-sm">
      <summary class="cursor-pointer">
        {{ t("medicion.destinos.guia") }}
      </summary>
      <ol class="mt-2 list-decimal space-y-1 pl-5">
        <li v-for="(paso, i) in guia" :key="i">{{ paso }}</li>
      </ol>
    </details>
  </article>
</template>
