<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import ReprogramarMiReserva from "@/components/ReprogramarMiReserva.vue";
import { api, mensajeDeError, noEncontrado } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";

/**
 * La cita desde el enlace de sus avisos, sin cuenta (ADR 0221): verla, cambiarla de
 * horario o cancelarla. El enlace lleva su firma; las reglas (hasta cuándo, qué pasa con
 * lo pagado, cuántos cambios) las pone el servidor, igual que desde la cuenta. Abrirla
 * no cambia nada: cancelar pide confirmarlo.
 */
interface Cita {
  id: string;
  estado: string;
  negocio: string | null;
  servicio: string;
  con: string | null;
  sucursal: string | null;
  direccion: string | null;
  inicia_en: string;
  zona_horaria: string;
  cancelar: { cancelable: boolean; mensaje: string };
}

const { t } = useI18n();
const ruta = useRoute();
const slug = computed(() => String(ruta.params.slug ?? ""));
const reserva = computed(() => String(ruta.params.reserva ?? ""));
const firma = computed(() =>
  typeof ruta.query.f === "string" ? ruta.query.f : "",
);
const base = computed(
  () =>
    `/api/v1/app/${encodeURIComponent(slug.value)}/citas/enlace/${encodeURIComponent(reserva.value)}`,
);

const cita = ref<Cita | null>(null);
const cargando = ref(true);
const invalido = ref(false);
const error = ref<string | null>(null);
const listo = ref<string | null>(null);
const cambiando = ref(false);
const cancelando = ref(false);

const cancelada = computed(() => cita.value?.estado === "cancelada");

function cuando(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona,
    weekday: "long",
    day: "numeric",
    month: "long",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(iso));
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Cita }>(base.value, {
      params: { f: firma.value },
    });
    cita.value = data.data;
  } catch (e) {
    if (noEncontrado(e)) invalido.value = true;
    else error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function cancelar(): Promise<void> {
  const c = cita.value;
  if (c === null || cancelando.value) return;
  const pregunta =
    t("citaSinCuenta.confirmarCancelar", {
      servicio: c.servicio,
      cuando: cuando(c.inicia_en, c.zona_horaria),
    }) +
    " " +
    c.cancelar.mensaje;
  if (
    !(await confirmar(pregunta, {
      aceptar: t("citaSinCuenta.siCancelar"),
      peligro: true,
    }))
  ) {
    return;
  }
  cancelando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: Cita }>(`${base.value}/cancelar`, {
      f: firma.value,
    });
    cita.value = data.data;
    listo.value = t("citaSinCuenta.listaCancelada");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cancelando.value = false;
  }
}

async function cambiada(): Promise<void> {
  cambiando.value = false;
  listo.value = t("citaSinCuenta.listaCambiada");
  await cargar();
}

onMounted(cargar);
</script>

<template>
  <main class="mx-auto max-w-lg px-4 py-12">
    <p
      v-if="cargando && cita === null"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t("citaSinCuenta.cargando") }}
    </p>
    <AvisoTono v-else-if="invalido" tono="aviso" data-prueba="enlace-invalido">
      {{ t("citaSinCuenta.invalido") }}
    </AvisoTono>
    <section
      v-else-if="cita"
      class="tu-card space-y-4 p-5"
      data-prueba="cita-sin-cuenta"
    >
      <header>
        <p
          v-if="cita.negocio"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ cita.negocio }}
        </p>
        <h1 class="text-xl font-semibold">{{ t("citaSinCuenta.titulo") }}</h1>
      </header>

      <AvisoTono v-if="listo" tono="exito" role="status">{{ listo }}</AvisoTono>
      <AvisoTono v-if="error" tono="peligro" role="alert">{{
        error
      }}</AvisoTono>

      <dl class="space-y-1">
        <dt class="sr-only">{{ cita.servicio }}</dt>
        <dd class="font-medium">{{ cita.servicio }}</dd>
        <dd class="first-letter:uppercase" data-prueba="cita-cuando">
          {{ cuando(cita.inicia_en, cita.zona_horaria) }}
        </dd>
        <dd v-if="cita.con" :style="{ color: 'var(--texto-suave)' }">
          {{ t("citaSinCuenta.con", { nombre: cita.con }) }}
        </dd>
        <dd v-if="cita.sucursal" :style="{ color: 'var(--texto-suave)' }">
          {{ cita.sucursal
          }}<template v-if="cita.direccion"> · {{ cita.direccion }}</template>
        </dd>
      </dl>

      <template v-if="cancelada">
        <p
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="cita-cancelada"
        >
          {{ t("citaSinCuenta.cancelada") }}
        </p>
        <RouterLink
          :to="{ name: 'agendar-cita', params: { slug } }"
          class="tu-btn tu-btn-primario"
        >
          {{ t("citaSinCuenta.agendarOtra") }}
        </RouterLink>
      </template>
      <template v-else>
        <ReprogramarMiReserva
          v-if="cambiando"
          :base="`/api/v1/app/${slug}`"
          :reserva-id="cita.id"
          :zona="cita.zona_horaria"
          :inicia-en="cita.inicia_en"
          :ruta="`${base}/reprogramar`"
          :firma="firma"
          @hecho="cambiada"
          @cerrar="cambiando = false"
        />
        <p
          v-if="!cita.cancelar.cancelable"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ cita.cancelar.mensaje }}
        </p>
        <div v-if="!cambiando" class="flex flex-wrap gap-2">
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            data-prueba="cambiar-cita"
            @click="
              listo = null;
              cambiando = true;
            "
          >
            {{ t("citaSinCuenta.cambiar") }}
          </button>
          <button
            v-if="cita.cancelar.cancelable"
            type="button"
            class="tu-btn tu-btn-peligro"
            :disabled="cancelando"
            :aria-busy="cancelando || undefined"
            data-prueba="cancelar-cita"
            @click="cancelar"
          >
            {{ t("citaSinCuenta.cancelar") }}
          </button>
        </div>
      </template>
    </section>
    <AvisoTono v-else-if="error" tono="peligro" role="alert">
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
  </main>
</template>
