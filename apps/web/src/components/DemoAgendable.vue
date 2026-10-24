<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import CampoCelular from "@/components/CampoCelular.vue";
import { trackEvent } from "@/lib/analytics";
import { api, erroresPorCampo, mensajeDeError } from "@/lib/api";
import { PRODUCTOS, type Producto } from "@/lib/producto";
import { tokenRecaptcha } from "@/lib/recaptcha";
import { PERFILES_POR_MODO } from "@/marketing/modalidades";

/**
 * Agenda una demo desde la landing (ADR 0193 y 0204), en tres pasos como el formulario
 * de la competencia: tu negocio (giro, si ya opera, cuántos alumnos o clientes, sus
 * sucursales y qué quiere resolver), el día y la hora libres (en la zona de ventas) y
 * sus datos (nombre, WhatsApp con lada y correo). Contra los envíos en masa: el sello
 * de tiempo de la página, el captcha, un campo trampa, el aviso de privacidad aceptado
 * y, en el servidor, límites por IP, correo y teléfono y una demo próxima por persona.
 * Si la plataforma no agenda demos, avisa con `apagadas`.
 */
const props = defineProps<{
  producto: Producto;
  giroInicial?: string | null;
}>();
const emit = defineEmits<{
  apagadas: [];
  agendada: [];
  minutos: [n: number];
}>();

interface Horarios {
  activas: boolean;
  minutos: number;
  zona: string;
  whatsapp: boolean;
  dias: { fecha: string; horas: string[] }[];
  sello: string;
}

const TAMANOS = ["1-20", "21-50", "51-100", "101-300", "301-500", "500+"];
const APERTURAS = ["menos_1_mes", "1_3_meses", "mas_3_meses", "no_se"];
const SUCURSALES = ["1", "2-4", "5+", "en_linea"];
const OBJETIVOS = [
  "reservas",
  "cobros",
  "app",
  "asistencia",
  "retencion",
  "agenda",
  "facturacion",
  "reportes",
];
const MAXIMO_OBJETIVOS = 2;
const PASOS = ["negocio", "horario", "datos"] as const;
type Paso = (typeof PASOS)[number];
// En qué paso se corrige cada campo que rechace el servidor.
const PASO_DE: Record<string, Paso> = {
  giro: "negocio",
  giro_otro: "negocio",
  negocio: "negocio",
  operando: "negocio",
  tamano: "negocio",
  apertura: "negocio",
  sucursales: "negocio",
  objetivos: "negocio",
  redes: "negocio",
  fecha: "horario",
  hora: "horario",
};

const { t, locale } = useI18n();
const modalidad = computed(() => PRODUCTOS[props.producto].modalidad);
const giros = computed(() => PERFILES_POR_MODO[modalidad.value]);
const horarios = ref<Horarios | null>(null);
const cargando = ref(true);
const paso = ref<Paso>("negocio");
const numeroPaso = computed(() => PASOS.indexOf(paso.value) + 1);

const datos = reactive({
  // El giro de la página de donde viene (`?giro=`), si es de este producto.
  giro:
    props.giroInicial && giros.value.includes(props.giroInicial)
      ? props.giroInicial
      : "",
  giro_otro: "",
  negocio: "",
  operando: "" as "" | "si" | "no",
  tamano: "",
  apertura: "",
  sucursales: "",
  objetivos: [] as string[],
  redes: "",
  fecha: "",
  hora: "",
  nombre: "",
  telefono: "",
  correo: "",
  acepta_whatsapp: false,
  acepta_aviso: false,
  // Campo trampa: queda vacío (un bot lo llena).
  sitio_web: "",
});
const avisoVersion = ref<number | null>(null);
const enviando = ref(false);
const listo = ref<string | null>(null);
const error = ref<string | null>(null);
// Los errores junto a su campo: los del paso (al seguir) y los del servidor.
const errores = ref<Record<string, string>>({});

const horasDelDia = computed(
  () => horarios.value?.dias.find((d) => d.fecha === datos.fecha)?.horas ?? [],
);
const zonaDeVentas = computed(() =>
  horarios.value?.zona === "America/Mexico_City" || !horarios.value
    ? t("demoAgendable.zona")
    : t("demoAgendable.zonaOtra", { zona: horarios.value.zona }),
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
function alternarObjetivo(o: string): void {
  const i = datos.objetivos.indexOf(o);
  if (i >= 0) {
    datos.objetivos.splice(i, 1);
  } else if (datos.objetivos.length < MAXIMO_OBJETIVOS) {
    datos.objetivos.push(o);
  }
}

async function cargarHorarios(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Horarios }>("/api/v1/demos");
    horarios.value = data.data;
    emit("minutos", data.data.minutos);
    if (!data.data.dias.some((d) => d.fecha === datos.fecha)) {
      datos.fecha = data.data.dias[0]?.fecha ?? "";
      datos.hora = "";
    }
    if (!data.data.activas || data.data.dias.length === 0) {
      emit("apagadas");
    }
  } catch {
    horarios.value = null;
    emit("apagadas");
  }
}

onMounted(async () => {
  await cargarHorarios();
  cargando.value = false;
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

// Lo que falta en un paso, campo por campo.
function faltantes(p: Paso): Record<string, string> {
  const falta = t("demoAgendable.obligatorio");
  const f: Record<string, string> = {};
  if (p === "negocio") {
    if (datos.giro === "") f.giro = falta;
    if (datos.giro === "otro" && datos.giro_otro.trim().length < 2)
      f.giro_otro = falta;
    if (datos.negocio.trim().length < 2) f.negocio = falta;
    if (datos.operando === "") f.operando = falta;
    if (datos.operando === "si" && datos.tamano === "") f.tamano = falta;
    if (datos.operando === "no" && datos.apertura === "") f.apertura = falta;
    if (datos.sucursales === "") f.sucursales = falta;
    if (datos.objetivos.length === 0) f.objetivos = falta;
  }
  if (p === "horario") {
    if (datos.fecha === "" || datos.hora === "") f.hora = falta;
  }
  if (p === "datos") {
    if (datos.nombre.trim().length < 2) f.nombre = falta;
    if (datos.telefono.replace(/\D/g, "").length < 8)
      f.telefono = t("demoAgendable.telefonoInvalido");
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(datos.correo.trim()))
      f.correo = t("demoAgendable.correoInvalido");
    if (!datos.acepta_aviso) f.acepta_aviso = falta;
  }
  return f;
}

function seguir(): void {
  errores.value = faltantes(paso.value);
  if (Object.keys(errores.value).length > 0) {
    error.value = t("demoAgendable.faltan");
    return;
  }
  error.value = null;
  paso.value = PASOS[numeroPaso.value] ?? paso.value;
}
function regresar(): void {
  errores.value = {};
  error.value = null;
  paso.value = PASOS[numeroPaso.value - 2] ?? paso.value;
}

async function agendar(): Promise<void> {
  if (enviando.value || horarios.value === null) {
    return;
  }
  errores.value = faltantes("datos");
  if (Object.keys(errores.value).length > 0) {
    error.value = t("demoAgendable.faltan");
    return;
  }
  enviando.value = true;
  error.value = null;
  try {
    await api.post("/api/v1/demos", {
      producto: props.producto,
      fecha: datos.fecha,
      hora: datos.hora,
      nombre: datos.nombre,
      negocio: datos.negocio,
      giro: datos.giro === "otro" ? null : datos.giro,
      giro_otro: datos.giro === "otro" ? datos.giro_otro : null,
      operando: datos.operando === "si",
      tamano: datos.operando === "si" ? datos.tamano : null,
      apertura: datos.operando === "no" ? datos.apertura : null,
      sucursales: datos.sucursales,
      objetivos: datos.objetivos,
      redes: datos.redes.trim() || null,
      correo: datos.correo,
      telefono: datos.telefono,
      acepta_whatsapp: datos.acepta_whatsapp,
      acepta_aviso: datos.acepta_aviso,
      aviso_version: avisoVersion.value,
      sitio_web: datos.sitio_web || undefined,
      sello: horarios.value.sello,
      recaptcha_token: await tokenRecaptcha("demo"),
    });
    listo.value = `${nombreDia(datos.fecha)} · ${datos.hora}`;
    // Sin datos de la persona: solo que se agendó y de qué producto.
    trackEvent("demo_scheduled", { product: props.producto });
    emit("agendada");
  } catch (e) {
    errores.value = erroresPorCampo(e);
    error.value = mensajeDeError(e);
    // Al paso donde se corrige el primer campo que rechazó el servidor.
    const primero = Object.keys(errores.value).find((c) => c in PASO_DE);
    if (primero !== undefined) {
      paso.value = PASO_DE[primero];
    }
    // Un horario que ya no está o un sello vencido: horarios y sello al día.
    if ("hora" in errores.value || "sello" in errores.value) {
      await cargarHorarios();
    }
  } finally {
    enviando.value = false;
  }
}

const registro = computed(() => ({
  name: "registro",
  query: { modo: modalidad.value },
}));
</script>

<template>
  <!-- Sin demos (apagadas o sin horarios) no se pinta: quien lo usa lo avisa. -->
  <div
    v-if="cargando || listo || (horarios?.activas && horarios.dias.length > 0)"
    class="tu-card p-6 sm:p-8 text-left"
    data-prueba="demo-agendable"
    :aria-busy="cargando || undefined"
  >
    <p
      v-if="cargando"
      class="text-sm"
      role="status"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t("demoAgendable.pagina.cargando") }}
    </p>

    <!-- Agendada: cuándo, qué sigue y, mientras, crear la cuenta. -->
    <div v-else-if="listo" data-prueba="demo-listo">
      <h2 class="text-xl font-medium">{{ t("demoAgendable.listo") }}</h2>
      <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
        {{
          horarios?.zona === "America/Mexico_City"
            ? t("demoAgendable.listoTexto", { cuando: listo })
            : t("demoAgendable.listoOtraZona", {
                cuando: listo,
                zona: horarios?.zona,
              })
        }}
      </p>
      <p class="mt-4">{{ t("demoAgendable.listoMientras") }}</p>
      <RouterLink class="tu-btn tu-btn-secundario mt-3" :to="registro">
        {{ t("demoAgendable.pagina.crearCuenta") }}
      </RouterLink>
    </div>

    <form
      v-else-if="horarios"
      class="space-y-5"
      novalidate
      @submit.prevent="paso === 'datos' ? agendar() : seguir()"
    >
      <!-- Dónde va: paso y avance. -->
      <div>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{
            t("demoAgendable.pasos.etiqueta", {
              n: numeroPaso,
              de: PASOS.length,
            })
          }}
          · {{ t(`demoAgendable.pasos.${paso}`) }}
        </p>
        <div
          class="da-avance mt-2"
          role="progressbar"
          :aria-valuenow="numeroPaso"
          aria-valuemin="1"
          :aria-valuemax="PASOS.length"
          :aria-label="t(`demoAgendable.pasos.${paso}`)"
        >
          <span :style="{ width: `${(numeroPaso / PASOS.length) * 100}%` }" />
        </div>
      </div>

      <!-- Paso 1: tu negocio. -->
      <template v-if="paso === 'negocio'">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="tu-label" for="demo-giro">{{
              t("demoAgendable.giroPregunta")
            }}</label>
            <select
              id="demo-giro"
              v-model="datos.giro"
              class="tu-input"
              :aria-invalid="errores.giro ? 'true' : undefined"
              data-prueba="demo-giro"
            >
              <option value="" disabled>
                {{ t("demoAgendable.giroElige") }}
              </option>
              <option v-for="g in giros" :key="g" :value="g">
                {{ t(`registro.perfiles.${g}`) }}
              </option>
              <option value="otro">{{ t("demoAgendable.giroOtro") }}</option>
            </select>
            <span v-if="errores.giro" class="tu-error-campo">{{
              errores.giro
            }}</span>
          </div>
          <div v-if="datos.giro === 'otro'">
            <label class="tu-label" for="demo-giro-otro">{{
              t("demoAgendable.giroOtroCual")
            }}</label>
            <input
              id="demo-giro-otro"
              v-model="datos.giro_otro"
              class="tu-input"
              maxlength="80"
              :aria-invalid="errores.giro_otro ? 'true' : undefined"
            />
            <span v-if="errores.giro_otro" class="tu-error-campo">{{
              errores.giro_otro
            }}</span>
          </div>
          <div :class="datos.giro === 'otro' ? 'sm:col-span-2' : ''">
            <label class="tu-label" for="demo-negocio">{{
              t("demoAgendable.negocio")
            }}</label>
            <input
              id="demo-negocio"
              v-model="datos.negocio"
              class="tu-input"
              autocomplete="organization"
              maxlength="120"
              :aria-invalid="errores.negocio ? 'true' : undefined"
              data-prueba="demo-negocio"
            />
            <span v-if="errores.negocio" class="tu-error-campo">{{
              errores.negocio
            }}</span>
          </div>
        </div>

        <fieldset>
          <legend class="tu-label">{{ t("demoAgendable.operando") }}</legend>
          <div class="mt-1 flex flex-wrap gap-4">
            <label class="flex items-center gap-2">
              <input
                v-model="datos.operando"
                type="radio"
                value="si"
                name="demo-operando"
                data-prueba="demo-operando-si"
              />
              {{ t("demoAgendable.si") }}
            </label>
            <label class="flex items-center gap-2">
              <input
                v-model="datos.operando"
                type="radio"
                value="no"
                name="demo-operando"
              />
              {{ t("demoAgendable.noTodavia") }}
            </label>
          </div>
          <span v-if="errores.operando" class="tu-error-campo">{{
            errores.operando
          }}</span>
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
          <div v-if="datos.operando === 'si'">
            <label class="tu-label" for="demo-tamano">{{
              modalidad === "clases"
                ? t("demoAgendable.tamanoClases")
                : t("demoAgendable.tamanoCitas")
            }}</label>
            <select
              id="demo-tamano"
              v-model="datos.tamano"
              class="tu-input"
              :aria-invalid="errores.tamano ? 'true' : undefined"
              data-prueba="demo-tamano"
            >
              <option value="" disabled>
                {{ t("demoAgendable.giroElige") }}
              </option>
              <option v-for="o in TAMANOS" :key="o" :value="o">{{ o }}</option>
            </select>
            <span v-if="errores.tamano" class="tu-error-campo">{{
              errores.tamano
            }}</span>
          </div>
          <div v-if="datos.operando === 'no'">
            <label class="tu-label" for="demo-apertura">{{
              t("demoAgendable.apertura")
            }}</label>
            <select
              id="demo-apertura"
              v-model="datos.apertura"
              class="tu-input"
              :aria-invalid="errores.apertura ? 'true' : undefined"
            >
              <option value="" disabled>
                {{ t("demoAgendable.giroElige") }}
              </option>
              <option v-for="o in APERTURAS" :key="o" :value="o">
                {{ t(`demoAgendable.opciones.apertura.${o}`) }}
              </option>
            </select>
            <span v-if="errores.apertura" class="tu-error-campo">{{
              errores.apertura
            }}</span>
          </div>
          <div>
            <label class="tu-label" for="demo-sucursales">{{
              t("demoAgendable.sucursales")
            }}</label>
            <select
              id="demo-sucursales"
              v-model="datos.sucursales"
              class="tu-input"
              :aria-invalid="errores.sucursales ? 'true' : undefined"
              data-prueba="demo-sucursales"
            >
              <option value="" disabled>
                {{ t("demoAgendable.giroElige") }}
              </option>
              <option v-for="o in SUCURSALES" :key="o" :value="o">
                {{ t(`demoAgendable.opciones.sucursales.${o}`) }}
              </option>
            </select>
            <span v-if="errores.sucursales" class="tu-error-campo">{{
              errores.sucursales
            }}</span>
          </div>
        </div>

        <fieldset>
          <legend class="tu-label">{{ t("demoAgendable.objetivos") }}</legend>
          <div class="mt-1 grid gap-2 sm:grid-cols-2">
            <label
              v-for="o in OBJETIVOS"
              :key="o"
              class="flex items-center gap-2 text-sm"
            >
              <input
                type="checkbox"
                :checked="datos.objetivos.includes(o)"
                :disabled="
                  !datos.objetivos.includes(o) &&
                  datos.objetivos.length >= MAXIMO_OBJETIVOS
                "
                :data-objetivo="o"
                @change="alternarObjetivo(o)"
              />
              {{ t(`demoAgendable.opciones.objetivos.${o}`) }}
            </label>
          </div>
          <span v-if="errores.objetivos" class="tu-error-campo">{{
            errores.objetivos
          }}</span>
        </fieldset>

        <div>
          <label class="tu-label" for="demo-redes">{{
            t("demoAgendable.redes")
          }}</label>
          <input
            id="demo-redes"
            v-model="datos.redes"
            class="tu-input"
            maxlength="190"
            :placeholder="t('demoAgendable.redesPh')"
            :aria-invalid="errores.redes ? 'true' : undefined"
          />
          <span v-if="errores.redes" class="tu-error-campo">{{
            errores.redes
          }}</span>
        </div>
      </template>

      <!-- Paso 2: el día y la hora libres. -->
      <template v-else-if="paso === 'horario'">
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
        <fieldset>
          <legend class="tu-label">{{ t("demoAgendable.hora") }}</legend>
          <div v-if="horasDelDia.length > 0" class="mt-2 flex flex-wrap gap-2">
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
          <p
            v-else
            class="mt-2 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ t("demoAgendable.sinHoras") }}
          </p>
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ zonaDeVentas }}
          </p>
          <span v-if="errores.hora || errores.fecha" class="tu-error-campo">{{
            errores.hora ?? errores.fecha
          }}</span>
        </fieldset>
      </template>

      <!-- Paso 3: sus datos para llamarle. -->
      <template v-else>
        <p class="text-sm" data-prueba="demo-resumen-horario">
          <span class="font-medium"
            >{{ nombreDia(datos.fecha) }} · {{ datos.hora }}</span
          >
          <button
            type="button"
            class="tu-enlace ml-2"
            @click="paso = 'horario'"
          >
            {{ t("demoAgendable.cambiarHorario") }}
          </button>
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="tu-label" for="demo-nombre">{{
              t("demoAgendable.nombre")
            }}</label>
            <input
              id="demo-nombre"
              v-model="datos.nombre"
              class="tu-input"
              autocomplete="name"
              maxlength="120"
              :aria-invalid="errores.nombre ? 'true' : undefined"
              data-prueba="demo-nombre"
            />
            <span v-if="errores.nombre" class="tu-error-campo">{{
              errores.nombre
            }}</span>
          </div>
          <div>
            <label class="tu-label" for="demo-correo">{{
              t("demoAgendable.correo")
            }}</label>
            <input
              id="demo-correo"
              v-model="datos.correo"
              class="tu-input"
              type="email"
              autocomplete="email"
              maxlength="190"
              :aria-invalid="errores.correo ? 'true' : undefined"
              data-prueba="demo-correo"
            />
            <span v-if="errores.correo" class="tu-error-campo">{{
              errores.correo
            }}</span>
          </div>
          <div class="sm:col-span-2">
            <label class="tu-label" for="demo-telefono">{{
              t("demoAgendable.telefono")
            }}</label>
            <CampoCelular
              id="demo-telefono"
              v-model="datos.telefono"
              autocomplete="tel"
              :aria-invalid="errores.telefono ? 'true' : undefined"
              data-prueba="demo-telefono"
            />
            <span v-if="errores.telefono" class="tu-error-campo">{{
              errores.telefono
            }}</span>
          </div>
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
          <input
            v-model="datos.acepta_aviso"
            type="checkbox"
            class="mt-1"
            :aria-invalid="errores.acepta_aviso ? 'true' : undefined"
            data-prueba="demo-aviso"
          />
          <span>
            {{ t("interesados.aviso") }}
            <RouterLink class="tu-enlace" to="/aviso-de-privacidad">{{
              t("interesados.avisoEnlace")
            }}</RouterLink>
          </span>
        </label>
        <span v-if="errores.acepta_aviso" class="tu-error-campo">{{
          errores.acepta_aviso
        }}</span>
      </template>

      <AvisoTono v-if="error" tono="peligro" role="alert">
        {{ error }}
      </AvisoTono>

      <div class="flex flex-wrap items-center gap-3">
        <button
          v-if="paso !== 'negocio'"
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="enviando"
          @click="regresar"
        >
          {{ t("demoAgendable.anterior") }}
        </button>
        <button
          class="tu-btn tu-btn-primario"
          type="submit"
          :disabled="enviando"
          :aria-busy="enviando || undefined"
          :data-prueba="paso === 'datos' ? 'agendar-demo' : 'demo-siguiente'"
        >
          {{
            paso !== "datos"
              ? t("demoAgendable.siguiente")
              : enviando
                ? t("demoAgendable.agendando")
                : t("demoAgendable.agendar")
          }}
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped>
/* El avance de los pasos: una barra fina, sin adornos. */
.da-avance {
  height: 0.25rem;
  border-radius: 999px;
  background: var(--borde);
  overflow: hidden;
}
.da-avance > span {
  display: block;
  height: 100%;
  background: var(--primario);
  transition: width 0.2s ease;
}
</style>
