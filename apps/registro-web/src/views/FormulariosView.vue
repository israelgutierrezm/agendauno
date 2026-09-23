<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

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
interface Miembro {
  id: string;
  nombre: string;
  nombre_completo: string;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("formularios.gestionar"));
const puedeResponder = computed(() => sesion.puede("formularios.responder"));

const formularios = ref<Formulario[]>([]);
const miembros = ref<Miembro[]>([]);
const seleccionadoId = ref<string | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const mensaje = ref<string | null>(null);

const nuevo = ref({ nombre: "", aplica_a: "miembro" });
const creando = ref(false);

const nuevoCampo = ref({
  etiqueta: "",
  tipo: "texto",
  obligatorio: false,
  opciones: "",
});
const agregandoCampo = ref(false);

const respuesta = reactive<{
  persona: string;
  texto: Record<string, string>;
  bool: Record<string, boolean>;
}>({
  persona: "",
  texto: {},
  bool: {},
});
const guardando = ref(false);

const seleccionado = computed(
  () => formularios.value.find((f) => f.id === seleccionadoId.value) ?? null,
);

function nombreMiembro(m: Miembro): string {
  return m.nombre_completo || m.nombre;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [f, m] = await Promise.all([
      api.get<{ data: Formulario[] }>(`${base.value}/formularios`),
      api.get<{ data: Miembro[] }>(`${base.value}/miembros`, {
        params: { tipo: "miembro" },
      }),
    ]);
    formularios.value = f.data.data;
    miembros.value = m.data.data;
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

async function enviarRespuesta(): Promise<void> {
  if (seleccionado.value === null || respuesta.persona === "") {
    return;
  }
  guardando.value = true;
  error.value = null;
  mensaje.value = null;
  try {
    const valores: Record<string, string | number | boolean> = {};
    for (const c of seleccionado.value.campos) {
      if (c.tipo === "booleano") {
        valores[c.id] = respuesta.bool[c.id] ?? false;
      } else {
        const v = respuesta.texto[c.id];
        if (v !== undefined && v !== "") {
          valores[c.id] = c.tipo === "numero" ? Number(v) : v;
        }
      }
    }
    await api.post(
      `${base.value}/formularios/${seleccionado.value.id}/respuestas`,
      {
        persona_id: respuesta.persona,
        valores,
      },
    );
    mensaje.value = "ok";
    respuesta.texto = {};
    respuesta.bool = {};
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 py-10">
    <EncabezadoSeccion :titulo="$t('formularios.titulo')" />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <div v-if="!cargando" class="mt-6 grid gap-6 md:grid-cols-[240px_1fr]">
      <!-- Lista + nuevo -->
      <div class="tu-card p-4 h-max">
        <p
          v-if="formularios.length === 0"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("formularios.vacio") }}
        </p>
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
          v-if="puedeGestionar"
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
        <!-- Constructor de campos -->
        <div class="tu-card p-6">
          <h2 class="font-bold text-lg">
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
              <span v-if="c.obligatorio" class="tu-badge tu-badge-aviso"
                >obligatorio</span
              >
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

        <!-- Responder (renderizado dinamico) -->
        <div v-if="puedeResponder" class="tu-card p-6">
          <h2 class="font-bold text-lg">
            {{ $t("formularios.responder.titulo") }}
          </h2>
          <p
            v-if="seleccionado.campos.length === 0"
            class="mt-2 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("formularios.responder.sinCampos") }}
          </p>
          <form v-else class="mt-3 space-y-3" @submit.prevent="enviarRespuesta">
            <div>
              <label class="tu-label" for="rp">{{
                $t("formularios.responder.persona")
              }}</label>
              <select
                id="rp"
                v-model="respuesta.persona"
                class="tu-input"
                required
              >
                <option value="" disabled>
                  {{ $t("formularios.responder.elegir") }}
                </option>
                <option v-for="m in miembros" :key="m.id" :value="m.id">
                  {{ nombreMiembro(m) }}
                </option>
              </select>
            </div>

            <div v-for="c in seleccionado.campos" :key="c.id">
              <label class="tu-label" :for="`c-${c.id}`">
                {{ c.etiqueta
                }}<span v-if="c.obligatorio" style="color: var(--error)">
                  *</span
                >
              </label>
              <textarea
                v-if="c.tipo === 'textarea'"
                :id="`c-${c.id}`"
                v-model="respuesta.texto[c.id]"
                class="tu-input"
                rows="2"
              />
              <select
                v-else-if="c.tipo === 'seleccion'"
                :id="`c-${c.id}`"
                v-model="respuesta.texto[c.id]"
                class="tu-input"
              >
                <option value="">—</option>
                <option v-for="o in c.opciones ?? []" :key="o" :value="o">
                  {{ o }}
                </option>
              </select>
              <label
                v-else-if="c.tipo === 'booleano'"
                class="flex items-center gap-2 text-sm"
              >
                <input
                  :id="`c-${c.id}`"
                  v-model="respuesta.bool[c.id]"
                  type="checkbox"
                />
                {{ c.etiqueta }}
              </label>
              <input
                v-else
                :id="`c-${c.id}`"
                v-model="respuesta.texto[c.id]"
                class="tu-input"
                :type="
                  c.tipo === 'numero'
                    ? 'number'
                    : c.tipo === 'fecha'
                      ? 'date'
                      : 'text'
                "
              />
            </div>

            <p
              v-if="mensaje"
              class="text-sm"
              :style="{ color: 'var(--exito)' }"
            >
              {{ $t("formularios.responder.guardado") }}
            </p>
            <button
              class="tu-btn tu-btn-primario"
              type="submit"
              :disabled="guardando || respuesta.persona === ''"
            >
              {{ $t("formularios.responder.guardar") }}
            </button>
          </form>
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
