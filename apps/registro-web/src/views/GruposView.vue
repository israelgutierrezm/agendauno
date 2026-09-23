<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Grupo {
  id: string;
  nombre: string;
  oferta: string | null;
  inscritos: number;
  activo: boolean;
}
interface Plantilla {
  id: string;
  oferta: string | null;
  dias_semana: number[];
  hora_local: string;
}
interface Miembro {
  id: string;
  nombre: string;
  nombre_completo: string;
}
interface Inscripcion {
  id: string;
  persona: string | null;
  activo: boolean;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("agenda.gestionar"));

const grupos = ref<Grupo[]>([]);
const plantillas = ref<Plantilla[]>([]);
const miembros = ref<Miembro[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

const nuevo = ref({ nombre: "", plantillaId: "" });
const creando = ref(false);
const abierto = ref(false);

function abrir(): void {
  nuevo.value = { nombre: "", plantillaId: "" };
  error.value = null;
  abierto.value = true;
}
function cerrar(): void {
  abierto.value = false;
}

const seleccionado = ref<Grupo | null>(null);
const inscripciones = ref<Inscripcion[]>([]);
const miembroId = ref("");
const inscribiendo = ref(false);
const mensaje = ref<string | null>(null);

const LETRAS = ["", "L", "M", "M", "J", "V", "S", "D"];
function etiquetaPlantilla(p: Plantilla): string {
  const dias = [...p.dias_semana]
    .sort((a, b) => a - b)
    .map((d) => LETRAS[d])
    .join(" ");
  return `${p.oferta ?? "—"} · ${dias} ${p.hora_local}`;
}
function nombreMiembro(m: Miembro): string {
  return m.nombre_completo || m.nombre;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [g, p, m] = await Promise.all([
      api.get<{ data: Grupo[] }>(`${base.value}/grupos`),
      api.get<{ data: Plantilla[] }>(`${base.value}/plantillas-horario`),
      api.get<{ data: Miembro[] }>(`${base.value}/miembros`, {
        params: { tipo: "miembro" },
      }),
    ]);
    grupos.value = g.data.data;
    plantillas.value = p.data.data;
    miembros.value = m.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function crear(): Promise<void> {
  creando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/grupos`, {
      nombre: nuevo.value.nombre,
      plantilla_id: nuevo.value.plantillaId,
    });
    abierto.value = false;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    creando.value = false;
  }
}

async function seleccionar(g: Grupo): Promise<void> {
  seleccionado.value = g;
  inscripciones.value = [];
  miembroId.value = "";
  mensaje.value = null;
  try {
    const { data } = await api.get<{ data: Inscripcion[] }>(
      `${base.value}/grupos/${g.id}/inscripciones`,
    );
    inscripciones.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function inscribir(): Promise<void> {
  if (seleccionado.value === null || miembroId.value === "") {
    return;
  }
  inscribiendo.value = true;
  mensaje.value = null;
  error.value = null;
  try {
    const { data } = await api.post<{ data: { reservadas: number } }>(
      `${base.value}/grupos/${seleccionado.value.id}/inscripciones`,
      { persona_id: miembroId.value },
    );
    mensaje.value = t("cursos.reservadas", { n: data.data.reservadas });
    miembroId.value = "";
    await seleccionar(seleccionado.value);
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    inscribiendo.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion :titulo="$t('cursos.titulo')" :total="grupos.length" />
      <button
        v-if="puedeGestionar && plantillas.length > 0"
        class="tu-btn tu-btn-primario"
        type="button"
        @click="abrir"
      >
        {{ $t("cursos.crear") }}
      </button>
    </div>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <!-- Lista de grupos -->
      <p
        v-if="grupos.length === 0"
        class="mt-8 text-center text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("cursos.vacio") }}
      </p>
      <div v-else class="mt-6 grid sm:grid-cols-2 gap-3">
        <button
          v-for="g in grupos"
          :key="g.id"
          class="tu-card p-4 text-left"
          :style="
            seleccionado?.id === g.id
              ? { outline: '2px solid var(--primario)' }
              : {}
          "
          @click="seleccionar(g)"
        >
          <div class="font-semibold">{{ g.nombre }}</div>
          <div class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ g.oferta ?? "—" }}
          </div>
          <div class="mt-2 tu-badge">
            {{ $t("cursos.inscritos", { n: g.inscritos }) }}
          </div>
        </button>
      </div>

      <!-- Grupo seleccionado: inscritos + inscribir -->
      <div v-if="seleccionado" class="mt-6 tu-card p-5">
        <h2 class="font-bold">
          {{ seleccionado.nombre }} · {{ $t("cursos.inscritosTitulo") }}
        </h2>

        <form
          v-if="puedeGestionar"
          class="mt-3 flex flex-wrap items-end gap-2"
          @submit.prevent="inscribir"
        >
          <div class="flex-1 min-w-[180px]">
            <label class="tu-label" for="im">{{
              $t("cursos.inscribir")
            }}</label>
            <select id="im" v-model="miembroId" class="tu-input" required>
              <option value="" disabled>
                {{ $t("cursos.elegirMiembro") }}
              </option>
              <option v-for="m in miembros" :key="m.id" :value="m.id">
                {{ nombreMiembro(m) }}
              </option>
            </select>
          </div>
          <button
            class="tu-btn tu-btn-primario"
            type="submit"
            :disabled="inscribiendo || miembroId === ''"
          >
            {{
              inscribiendo ? $t("cursos.inscribiendo") : $t("cursos.inscribir")
            }}
          </button>
        </form>
        <p
          v-if="mensaje"
          class="mt-2 text-sm"
          :style="{ color: 'var(--exito)' }"
        >
          {{ mensaje }}
        </p>

        <ul v-if="inscripciones.length > 0" class="mt-4 space-y-2">
          <li
            v-for="i in inscripciones"
            :key="i.id"
            class="flex items-center gap-2 text-sm"
          >
            <AvatarIniciales :nombre="i.persona" tam="sm" />
            <span>{{ i.persona ?? "—" }}</span>
          </li>
        </ul>
        <p v-else class="mt-4 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("cursos.sinInscritos") }}
        </p>
      </div>
    </template>

    <!-- Nuevo grupo (drawer lateral) -->
    <PanelLateral
      :abierto="abierto"
      :titulo="$t('cursos.nuevo')"
      @cerrar="cerrar"
    >
      <form class="space-y-4" @submit.prevent="crear">
        <div>
          <label class="tu-label" for="gn">{{ $t("cursos.nombre") }}</label>
          <input id="gn" v-model="nuevo.nombre" class="tu-input" required />
        </div>
        <div>
          <label class="tu-label" for="gp">{{ $t("cursos.horario") }}</label>
          <select id="gp" v-model="nuevo.plantillaId" class="tu-input" required>
            <option value="" disabled>{{ $t("cursos.horario") }}</option>
            <option v-for="p in plantillas" :key="p.id" :value="p.id">
              {{ etiquetaPlantilla(p) }}
            </option>
          </select>
        </div>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button class="tu-btn tu-btn-fantasma" type="button" @click="cerrar">
            {{ $t("comun.cancelar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="
              creando || nuevo.nombre === '' || nuevo.plantillaId === ''
            "
            @click="crear"
          >
            {{ creando ? $t("cursos.creando") : $t("cursos.crear") }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>
