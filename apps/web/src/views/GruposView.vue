<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import BuscarPersona from "@/components/BuscarPersona.vue";
import AvatarIniciales from "@/components/AvatarIniciales.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
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
// Quedó inscrito sin ninguna clase reservada: se avisa (no es un éxito completo).
const sinReservas = ref(false);

// Abreviaturas que no se confunden (martes y miércoles empiezan igual).
const LETRAS = ["", "Lu", "Ma", "Mi", "Ju", "Vi", "Sá", "Do"];
function etiquetaPlantilla(p: Plantilla): string {
  const dias = [...p.dias_semana]
    .sort((a, b) => a - b)
    .map((d) => LETRAS[d])
    .join(" ");
  return `${p.oferta ?? "—"} · ${dias} ${p.hora_local}`;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [g, p] = await Promise.all([
      api.get<{ data: Grupo[] }>(`${base.value}/grupos`),
      api.get<{ data: Plantilla[] }>(`${base.value}/plantillas-horario`),
    ]);
    grupos.value = g.data.data;
    plantillas.value = p.data.data;
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
  // Inscribir reserva todas sus clases próximas con su plan: se confirma.
  if (
    !(await confirmar(
      t("cursos.confirmarInscribir", { grupo: seleccionado.value.nombre }),
      { aceptar: t("cursos.inscribir") },
    ))
  ) {
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
    const n = data.data.reservadas;
    sinReservas.value = n === 0;
    mensaje.value =
      n === 0 ? t("cursos.sinReservadas") : t("cursos.reservadas", { n });
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

// Indicadores de los grupos (patrón de los listados).
const indicadores = computed<Indicador[]>(() => {
  const activos = grupos.value.filter((g) => g.activo);
  const inscritos = activos.reduce((suma, g) => suma + g.inscritos, 0);
  return [
    {
      clave: "grupos",
      etiqueta: t("gruposVisual.kpi.grupos"),
      valor: String(activos.length),
      icono: "grupos",
    },
    {
      clave: "inscritos",
      etiqueta: t("gruposVisual.kpi.inscritos"),
      valor: String(inscritos),
      icono: "personas",
    },
    {
      clave: "promedio",
      etiqueta: t("gruposVisual.kpi.promedio"),
      valor: activos.length > 0 ? (inscritos / activos.length).toFixed(1) : "—",
      icono: "reportes",
    },
  ];
});
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('cursos.titulo')"
      :subtitulo="$t('gruposVisual.subtitulo')"
    >
      <template v-if="puedeGestionar && plantillas.length > 0" #acciones>
        <button
          class="tu-btn tu-btn-primario tu-btn-crear"
          type="button"
          @click="abrir"
        >
          {{ $t("cursos.crear") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <EstadoVacio
        v-if="grupos.length === 0 && !error"
        class="tu-card mt-6"
        icono="grupos"
        :titulo="$t('cursos.vacio')"
      />
      <template v-else-if="grupos.length > 0">
        <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

        <div class="mt-5 grid gap-5 lg:grid-cols-5">
          <!-- Grupos -->
          <div class="tu-card overflow-x-auto lg:col-span-3">
            <table class="tu-tabla">
              <thead>
                <tr>
                  <th>{{ $t("gruposVisual.col.grupo") }}</th>
                  <th class="text-right">
                    {{ $t("gruposVisual.col.inscritos") }}
                  </th>
                  <th>{{ $t("gruposVisual.col.estado") }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="g in grupos"
                  :key="g.id"
                  class="gr-fila"
                  :class="{ 'gr-elegida': seleccionado?.id === g.id }"
                  :aria-selected="seleccionado?.id === g.id"
                  data-prueba="grupo"
                  @click="seleccionar(g)"
                >
                  <td>
                    <button
                      type="button"
                      class="gr-nombre"
                      @click.stop="seleccionar(g)"
                    >
                      {{ g.nombre }}
                    </button>
                    <span class="tu-sub">{{ g.oferta ?? "—" }}</span>
                  </td>
                  <td class="text-right tabular-nums">{{ g.inscritos }}</td>
                  <td>
                    <!-- Estado: punto + texto. -->
                    <span
                      class="tu-badge"
                      :class="{ 'tu-badge-exito': g.activo }"
                      >{{
                        g.activo
                          ? $t("gruposVisual.activo")
                          : $t("gruposVisual.inactivo")
                      }}</span
                    >
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Grupo elegido: inscritos e inscribir -->
          <aside
            class="tu-card p-5 lg:col-span-2 h-fit"
            data-prueba="detalle-grupo"
          >
            <p
              v-if="!seleccionado"
              class="text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("gruposVisual.elige") }}
            </p>
            <template v-else>
              <h2 class="font-medium">{{ seleccionado.nombre }}</h2>
              <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("cursos.inscritosTitulo") }}
              </p>

              <form
                v-if="puedeGestionar"
                class="mt-4 flex flex-wrap items-end gap-2"
                @submit.prevent="inscribir"
              >
                <div class="flex-1 min-w-[180px]">
                  <label class="tu-label" for="im">{{
                    $t("cursos.inscribir")
                  }}</label>
                  <BuscarPersona
                    v-model="miembroId"
                    campo-id="im"
                    :buscar-en="`${base}/miembros`"
                    :parametros="{ tipo: 'miembro' }"
                  />
                </div>
                <button
                  class="tu-btn tu-btn-primario"
                  type="submit"
                  :disabled="inscribiendo || miembroId === ''"
                >
                  {{
                    inscribiendo
                      ? $t("cursos.inscribiendo")
                      : $t("cursos.inscribir")
                  }}
                </button>
              </form>
              <p
                v-if="mensaje"
                class="mt-2 text-sm"
                role="status"
                :style="{
                  color: sinReservas ? 'var(--aviso)' : 'var(--exito)',
                }"
              >
                {{ mensaje }}
              </p>

              <ul v-if="inscripciones.length > 0" class="gr-inscritos mt-4">
                <li v-for="i in inscripciones" :key="i.id">
                  <AvatarIniciales :nombre="i.persona" tam="sm" />
                  <span>{{ i.persona ?? "—" }}</span>
                </li>
              </ul>
              <p
                v-else
                class="mt-4 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("cursos.sinInscritos") }}
              </p>
            </template>
          </aside>
        </div>
      </template>
    </template>

    <!-- Nuevo grupo (drawer lateral) -->
    <PanelLateral
      :abierto="abierto"
      :titulo="$t('cursos.nuevo')"
      @cerrar="cerrar"
    >
      <form class="space-y-4" @submit.prevent="crear">
        <p
          v-if="error"
          class="text-sm"
          role="alert"
          :style="{ color: 'var(--error)' }"
        >
          {{ error }}
        </p>
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

<style scoped>
.gr-fila {
  cursor: pointer;
}
.gr-fila:hover td,
.gr-elegida td {
  background: color-mix(in srgb, var(--texto-suave) 5%, transparent);
}
.gr-elegida td:first-child {
  box-shadow: inset 2px 0 0 var(--primario);
}
.gr-nombre {
  font-weight: 500;
  text-align: left;
}
.gr-inscritos {
  display: grid;
}
.gr-inscritos > li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0;
  border-top: 1px solid var(--borde);
  font-size: 0.875rem;
}
</style>
