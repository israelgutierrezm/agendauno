<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Constructor de formularios dinámicos y sus respuestas. Se llenan desde el
 * expediente de cada persona; aquí se diseñan y se consultan todas juntas.
 */
const { t } = useI18n();

interface Campo {
  id: string;
  etiqueta: string;
  tipo: string;
  obligatorio: boolean;
  opciones: string[] | null;
  orden: number;
}
interface Formulario {
  id: string;
  nombre: string;
  descripcion: string | null;
  aplica_a: string;
  activo: boolean;
  campos: Campo[];
}
type Valor = string | number | boolean | null;
interface Respuesta {
  id: string;
  persona: string | null;
  persona_id: string | null;
  persona_tipo: "miembro" | "instructor" | null;
  usuario_id: string | null;
  respondido_en: string | null;
  valores: Record<string, Valor>;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("formularios.gestionar"));
// Dos vistas de la misma pantalla (`?vista=`): el diseño de los formularios
// (Configuración › Documentos y privacidad) y la revisión de sus respuestas
// (Alumnos o Clientes › Respuestas de formularios).
const route = useRoute();
const soloRespuestas = computed(() => route.query.vista === "respuestas");

const formularios = ref<Formulario[]>([]);
const seleccionadoId = ref<string | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

const nuevo = ref({ nombre: "", aplica_a: "miembro" });
const creando = ref(false);

const nuevoCampo = ref({
  etiqueta: "",
  tipo: "texto",
  obligatorio: false,
  opciones: "",
});
const agregandoCampo = ref(false);

const respuestas = ref<Respuesta[]>([]);
const cargandoRespuestas = ref(false);
const abierta = ref<string | null>(null);

const seleccionado = computed(
  () => formularios.value.find((f) => f.id === seleccionadoId.value) ?? null,
);

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

function valor(c: Campo, v: Valor | undefined): string {
  if (v === undefined || v === null || v === "") {
    return t("expediente.sinValor");
  }
  if (c.tipo === "booleano") {
    return v === true ? t("expediente.si") : t("expediente.no");
  }
  if (c.tipo === "fecha" && typeof v === "string") {
    return fecha(`${v}T12:00:00`);
  }
  return String(v);
}

/** El expediente de la persona: la ficha del alumno o la del profesional. */
function expediente(r: Respuesta): string | null {
  if (r.persona_tipo === "miembro" && r.persona_id !== null) {
    return `/miembros/${r.persona_id}?seccion=expediente`;
  }
  if (r.usuario_id !== null) {
    return `/instructores/${r.usuario_id}?seccion=expediente`;
  }
  return null;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const f = await api.get<{ data: Formulario[] }>(
      `${base.value}/formularios`,
    );
    formularios.value = f.data.data;
    if (seleccionadoId.value === null && formularios.value.length > 0) {
      seleccionadoId.value = formularios.value[0].id;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function crearFormulario(): Promise<void> {
  creando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: Formulario }>(
      `${base.value}/formularios`,
      {
        nombre: nuevo.value.nombre,
        aplica_a: nuevo.value.aplica_a,
      },
    );
    nuevo.value = { nombre: "", aplica_a: "miembro" };
    await cargar();
    seleccionadoId.value = data.data.id;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    creando.value = false;
  }
}

async function agregarCampo(): Promise<void> {
  if (seleccionado.value === null) {
    return;
  }
  agregandoCampo.value = true;
  error.value = null;
  try {
    const opciones =
      nuevoCampo.value.tipo === "seleccion"
        ? nuevoCampo.value.opciones
            .split(",")
            .map((o) => o.trim())
            .filter((o) => o !== "")
        : null;
    await api.post(
      `${base.value}/formularios/${seleccionado.value.id}/campos`,
      {
        etiqueta: nuevoCampo.value.etiqueta,
        tipo: nuevoCampo.value.tipo,
        obligatorio: nuevoCampo.value.obligatorio,
        opciones,
      },
    );
    nuevoCampo.value = {
      etiqueta: "",
      tipo: "texto",
      obligatorio: false,
      opciones: "",
    };
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    agregandoCampo.value = false;
  }
}

async function cargarRespuestas(): Promise<void> {
  respuestas.value = [];
  abierta.value = null;
  // Solo en la vista de respuestas: el diseño no las consulta.
  if (
    !soloRespuestas.value ||
    !puedeGestionar.value ||
    seleccionadoId.value === null
  ) {
    return;
  }
  cargandoRespuestas.value = true;
  try {
    const { data } = await api.get<{ data: Respuesta[] }>(
      `${base.value}/formularios/${seleccionadoId.value}/respuestas`,
    );
    respuestas.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e, t("formulariosRespuestas.cargarError"));
  } finally {
    cargandoRespuestas.value = false;
  }
}
watch([seleccionadoId, soloRespuestas], cargarRespuestas);

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 py-10">
    <EncabezadoSeccion
      :titulo="
        soloRespuestas ? $t('nav.vistas.respuestas') : $t('formularios.titulo')
      "
    >
      <template v-if="puedeGestionar" #acciones>
        <!-- Misma ruta, otra vista: el router las daría por «página actual». -->
        <RouterLink
          v-if="soloRespuestas"
          :to="{ name: 'formularios' }"
          aria-current-value="false"
          class="tu-btn tu-btn-fantasma"
          >{{ $t("formularios.disenar") }}</RouterLink
        >
        <RouterLink
          v-else
          :to="{ name: 'formularios', query: { vista: 'respuestas' } }"
          aria-current-value="false"
          class="tu-btn tu-btn-fantasma"
          >{{ $t("formularios.verRespuestas") }}</RouterLink
        >
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <div v-if="!cargando" class="mt-6 grid gap-6 md:grid-cols-[240px_1fr]">
      <!-- Lista + nuevo -->
      <div class="tu-card p-4 h-max">
        <EstadoVacio
          v-if="formularios.length === 0"
          class="py-6"
          icono="formularios"
          compacto
          :titulo="$t('formularios.vacio')"
        />
        <ul v-else class="space-y-1">
          <li v-for="f in formularios" :key="f.id">
            <button
              type="button"
              class="w-full text-left rounded-lg px-3 py-2 text-sm font-medium"
              :style="{
                background:
                  f.id === seleccionadoId
                    ? 'var(--primario-suave)'
                    : 'transparent',
                color:
                  f.id === seleccionadoId
                    ? 'var(--primario-fuerte)'
                    : 'var(--texto)',
              }"
              @click="seleccionadoId = f.id"
            >
              {{ f.nombre }}
            </button>
          </li>
        </ul>

        <form
          v-if="puedeGestionar && !soloRespuestas"
          class="mt-4 border-t pt-3 space-y-2"
          :style="{ borderColor: 'var(--borde)' }"
          @submit.prevent="crearFormulario"
        >
          <p class="font-semibold text-sm">{{ $t("formularios.nuevo") }}</p>
          <input
            v-model="nuevo.nombre"
            class="tu-input"
            :placeholder="$t('formularios.nombrePh')"
            required
          />
          <select v-model="nuevo.aplica_a" class="tu-input">
            <option value="miembro">{{ $t("formularios.miembro") }}</option>
            <option value="instructor">
              {{ $t("formularios.instructor") }}
            </option>
            <option value="todos">{{ $t("formularios.todos") }}</option>
          </select>
          <button
            class="tu-btn tu-btn-fantasma w-full"
            type="submit"
            :disabled="creando || nuevo.nombre.trim() === ''"
          >
            {{ $t("formularios.crear") }}
          </button>
        </form>
      </div>

      <!-- Detalle del formulario -->
      <div v-if="seleccionado" class="space-y-6">
        <!-- Constructor de campos (diseño) -->
        <div v-if="!soloRespuestas" class="tu-card p-6">
          <h2 class="font-medium text-lg">
            {{ seleccionado.nombre }} · {{ $t("formularios.campos.titulo") }}
          </h2>
          <ul
            v-if="seleccionado.campos.length > 0"
            class="mt-3 space-y-1 text-sm"
          >
            <li
              v-for="c in seleccionado.campos"
              :key="c.id"
              class="flex items-center gap-2"
            >
              <span class="font-medium">{{ c.etiqueta }}</span>
              <span class="tu-badge">{{
                $t(`formularios.tipos.${c.tipo}`)
              }}</span>
              <span v-if="c.obligatorio" class="tu-badge tu-badge-aviso">{{
                $t("formularios.campos.obligatorio")
              }}</span>
            </li>
          </ul>
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("formularios.campos.vacio") }}
          </p>

          <form
            v-if="puedeGestionar"
            class="mt-4 border-t pt-4 grid sm:grid-cols-2 gap-3"
            :style="{ borderColor: 'var(--borde)' }"
            @submit.prevent="agregarCampo"
          >
            <input
              v-model="nuevoCampo.etiqueta"
              class="tu-input"
              :placeholder="$t('formularios.campos.etiquetaPh')"
              required
            />
            <select v-model="nuevoCampo.tipo" class="tu-input">
              <option
                v-for="tp in [
                  'texto',
                  'textarea',
                  'numero',
                  'fecha',
                  'booleano',
                  'seleccion',
                ]"
                :key="tp"
                :value="tp"
              >
                {{ $t(`formularios.tipos.${tp}`) }}
              </option>
            </select>
            <input
              v-if="nuevoCampo.tipo === 'seleccion'"
              v-model="nuevoCampo.opciones"
              class="tu-input sm:col-span-2"
              :placeholder="$t('formularios.campos.opciones')"
            />
            <label class="flex items-center gap-1.5 text-sm">
              <input v-model="nuevoCampo.obligatorio" type="checkbox" />
              {{ $t("formularios.campos.obligatorio") }}
            </label>
            <div class="sm:col-span-2">
              <button
                class="tu-btn tu-btn-fantasma"
                type="submit"
                :disabled="agregandoCampo || nuevoCampo.etiqueta.trim() === ''"
              >
                {{ $t("formularios.campos.agregar") }}
              </button>
            </div>
          </form>
        </div>

        <!-- Respuestas: se llenan desde el expediente de cada persona -->
        <div v-if="soloRespuestas" class="tu-card p-6">
          <h2 class="font-medium text-lg">
            {{ $t("formularios.respuestas.titulo") }}
            <span
              v-if="puedeGestionar && respuestas.length > 0"
              class="ml-1 font-normal tabular-nums"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ respuestas.length }}</span
            >
          </h2>
          <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("formulariosRespuestas.comoLlenar") }}
          </p>
          <template v-if="puedeGestionar">
            <p
              v-if="cargandoRespuestas"
              class="mt-4 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("comun.cargando") }}
            </p>
            <p
              v-else-if="respuestas.length === 0"
              class="mt-4 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("formulariosRespuestas.vacio") }}
            </p>
            <ul v-else class="mt-3">
              <li v-for="r in respuestas" :key="r.id" class="form-fila">
                <div class="flex w-full items-center justify-between gap-3">
                  <button
                    type="button"
                    class="min-w-0 text-left"
                    :aria-expanded="abierta === r.id"
                    @click="abierta = abierta === r.id ? null : r.id"
                  >
                    <span class="block font-medium truncate">{{
                      r.persona ?? "—"
                    }}</span>
                    <span
                      class="block text-xs"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{
                        $t("formulariosRespuestas.respondio", {
                          fecha: fecha(r.respondido_en),
                        })
                      }}</span
                    >
                  </button>
                  <RouterLink
                    v-if="expediente(r)"
                    :to="expediente(r) ?? ''"
                    class="tu-enlace shrink-0 text-sm"
                  >
                    {{ $t("formulariosRespuestas.abrirExpediente") }}
                  </RouterLink>
                </div>
                <dl
                  v-if="abierta === r.id"
                  class="mt-3 w-full rounded-xl border px-4 text-sm divide-y divide-[var(--borde)]"
                  :style="{
                    borderColor: 'var(--borde)',
                    background: 'var(--fondo)',
                  }"
                >
                  <div
                    v-for="c in seleccionado.campos"
                    :key="c.id"
                    class="flex items-baseline justify-between gap-4 py-2"
                  >
                    <dt :style="{ color: 'var(--texto-suave)' }">
                      {{ c.etiqueta }}
                    </dt>
                    <dd class="text-right font-medium">
                      {{ valor(c, r.valores[c.id]) }}
                    </dd>
                  </div>
                </dl>
              </li>
            </ul>
          </template>
        </div>
      </div>
      <div
        v-else
        class="tu-card p-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("formularios.seleccionar") }}
      </div>
    </div>
  </section>
</template>

<style scoped>
.form-fila {
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.form-fila:first-child {
  border-top: 0;
}
</style>
