<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import { trackEvent } from "@/lib/analytics";
import { api, camposConError, mensajeDeError } from "@/lib/api";
import { PRODUCTOS, type Producto } from "@/lib/producto";
import { tokenRecaptcha } from "@/lib/recaptcha";
import { PERFILES_POR_MODO } from "@/marketing/modalidades";

/**
 * Agenda una demo de 15 minutos desde la landing (ADR 0193): el día y la hora libres
 * (en la zona de ventas), los datos para llamarle y, si la plataforma confirma por
 * WhatsApp, si quiere su confirmación ahí (lo elige: no viene marcado). Con captcha y
 * el aviso de privacidad aceptado. Si la plataforma no agenda demos, no se muestra.
 */
const props = defineProps<{ producto: Producto }>();

interface Horarios {
  activas: boolean;
  minutos: number;
  zona: string;
  whatsapp: boolean;
  dias: { fecha: string; horas: string[] }[];
}

const { t, locale } = useI18n();
const horarios = ref<Horarios | null>(null);
const giros = computed(
  () => PERFILES_POR_MODO[PRODUCTOS[props.producto].modalidad],
);
const datos = reactive({
  fecha: "",
  hora: "",
  nombre: "",
  negocio: "",
  giro: "",
  correo: "",
  telefono: "",
  acepta_whatsapp: false,
  acepta_aviso: false,
  // Campo trampa: queda vacío (un bot lo llena).
  sitio_web: "",
});
const avisoVersion = ref<number | null>(null);
const enviando = ref(false);
const listo = ref<string | null>(null);
const error = ref<string | null>(null);
const errores = ref<string[]>([]);

const horasDelDia = computed(
  () => horarios.value?.dias.find((d) => d.fecha === datos.fecha)?.horas ?? [],
);

function nombreDia(fecha: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    weekday: "short",
    day: "numeric",
    month: "short",
    timeZone: "UTC",
  }).format(new Date(`${fecha}T12:00:00Z`));
}

function elegirDia(fecha: string): void {
  datos.fecha = fecha;
  datos.hora = "";
}

onMounted(async () => {
  try {
    const { data } = await api.get<{ data: Horarios }>("/api/v1/demos");
    horarios.value = data.data;
    datos.fecha = data.data.dias[0]?.fecha ?? "";
  } catch {
    horarios.value = null;
  }
  void api
    .get<{
      data: { versiones?: { aviso_privacidad: { version: number } | null } };
    }>("/api/v1/legales")
    .then(({ data }) => {
      avisoVersion.value =
        data.data.versiones?.aviso_privacidad?.version ?? null;
    })
    .catch(() => undefined);
});

const completo = computed(
  () =>
    datos.fecha !== "" &&
    datos.hora !== "" &&
    datos.nombre.trim() !== "" &&
    datos.negocio.trim() !== "" &&
    datos.correo.trim() !== "" &&
    datos.telefono.trim() !== "" &&
    datos.acepta_aviso,
);

async function agendar(): Promise<void> {
  if (!completo.value || enviando.value) {
    return;
  }
  enviando.value = true;
  error.value = null;
  errores.value = [];
  try {
    await api.post("/api/v1/demos", {
      producto: props.producto,
      fecha: datos.fecha,
      hora: datos.hora,
      nombre: datos.nombre,
      negocio: datos.negocio,
      giro: datos.giro || null,
      correo: datos.correo,
      telefono: datos.telefono,
      acepta_whatsapp: datos.acepta_whatsapp,
      acepta_aviso: datos.acepta_aviso,
      aviso_version: avisoVersion.value,
      sitio_web: datos.sitio_web || undefined,
      recaptcha_token: await tokenRecaptcha("demo"),
    });
    listo.value = `${nombreDia(datos.fecha)} · ${datos.hora}`;
    // Sin datos de la persona: solo que se agendó y de qué producto.
    trackEvent("demo_scheduled", { product: props.producto });
  } catch (e) {
    errores.value = camposConError(e);
    error.value = mensajeDeError(e);
  } finally {
    enviando.value = false;
  }
}
</script>

<template>
  <div
    v-if="horarios?.activas && horarios.dias.length > 0"
    class="tu-card p-6 sm:p-8 text-left"
    data-prueba="demo-agendable"
  >
    <template v-if="listo">
      <h3 class="text-xl font-medium">{{ t("demoAgendable.listo") }}</h3>
      <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
        {{ t("demoAgendable.listoTexto", { cuando: listo }) }}
      </p>
    </template>
    <form v-else class="space-y-5" novalidate @submit.prevent="agendar">
      <div>
        <h3 class="text-xl font-medium">
          {{ t("demoAgendable.titulo", { minutos: horarios.minutos }) }}
        </h3>
        <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
          {{ t("demoAgendable.texto") }}
        </p>
      </div>

      <fieldset>
        <legend class="tu-label">{{ t("demoAgendable.dia") }}</legend>
        <div class="mt-2 flex flex-wrap gap-2">
          <button
            v-for="d in horarios.dias"
            :key="d.fecha"
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            :aria-pressed="datos.fecha === d.fecha"
            :data-dia="d.fecha"
            @click="elegirDia(d.fecha)"
          >
            {{ nombreDia(d.fecha) }}
          </button>
        </div>
      </fieldset>
      <fieldset v-if="horasDelDia.length > 0">
        <legend class="tu-label">
          {{ t("demoAgendable.hora") }}
        </legend>
        <div class="mt-2 flex flex-wrap gap-2">
          <button
            v-for="h in horasDelDia"
            :key="h"
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico tabular-nums"
            :aria-pressed="datos.hora === h"
            :data-hora="h"
            @click="datos.hora = h"
          >
            {{ h }}
          </button>
        </div>
        <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ t("demoAgendable.zona") }}
        </p>
      </fieldset>

      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block">
          <span class="tu-label">{{ t("demoAgendable.nombre") }}</span>
          <input
            v-model="datos.nombre"
            class="tu-input"
            :aria-invalid="errores.includes('nombre')"
            autocomplete="name"
            maxlength="120"
            required
          />
        </label>
        <label class="block">
          <span class="tu-label">{{ t("demoAgendable.negocio") }}</span>
          <input
            v-model="datos.negocio"
            class="tu-input"
            :aria-invalid="errores.includes('negocio')"
            autocomplete="organization"
            maxlength="120"
            required
          />
        </label>
        <label class="block">
          <span class="tu-label">{{ t("demoAgendable.correo") }}</span>
          <input
            v-model="datos.correo"
            class="tu-input"
            :aria-invalid="errores.includes('correo')"
            type="email"
            autocomplete="email"
            maxlength="190"
            required
          />
        </label>
        <label class="block">
          <span class="tu-label">{{ t("demoAgendable.telefono") }}</span>
          <input
            v-model="datos.telefono"
            class="tu-input"
            :aria-invalid="errores.includes('telefono')"
            type="tel"
            autocomplete="tel"
            maxlength="30"
            required
          />
        </label>
        <label class="block sm:col-span-2">
          <span class="tu-label">{{ t("demoAgendable.giro") }}</span>
          <select v-model="datos.giro" class="tu-input">
            <option value="">{{ t("interesados.giroSin") }}</option>
            <option v-for="g in giros" :key="g" :value="g">
              {{ t(`registro.perfiles.${g}`) }}
            </option>
          </select>
        </label>
      </div>
      <!-- Trampa para bots: fuera de la vista y de la navegación. -->
      <input
        v-model="datos.sitio_web"
        class="sr-only"
        tabindex="-1"
        autocomplete="off"
        aria-hidden="true"
      />
      <label v-if="horarios.whatsapp" class="flex items-start gap-2 text-sm">
        <input v-model="datos.acepta_whatsapp" type="checkbox" class="mt-1" />
        <span>{{ t("demoAgendable.whatsapp") }}</span>
      </label>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="datos.acepta_aviso" type="checkbox" class="mt-1" />
        <span>
          {{ t("interesados.aviso") }}
          <RouterLink class="tu-enlace" to="/aviso-de-privacidad">{{
            t("interesados.avisoEnlace")
          }}</RouterLink>
        </span>
      </label>
      <AvisoTono v-if="error" tono="peligro" role="alert">
        {{ error }}
      </AvisoTono>
      <button
        class="tu-btn tu-btn-primario"
        type="submit"
        :disabled="enviando || !completo"
        data-prueba="agendar-demo"
      >
        {{
          enviando ? t("demoAgendable.agendando") : t("demoAgendable.agendar")
        }}
      </button>
    </form>
  </div>
</template>
