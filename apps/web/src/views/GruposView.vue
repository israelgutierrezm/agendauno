<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import BuscarPersona from "@/components/BuscarPersona.vue";
import AvatarIniciales from "@/components/AvatarIniciales.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
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
  persona_id?: string | null;
  activo: boolean;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("agenda.gestionar"));

const grupos = ref<Grupo[]>([]);
// Búsqueda (por grupo o clase), estado y páginas de 20.
const POR_PAGINA = 20;
const buscar = ref("");
const filtroEstado = ref<"activos" | "inactivos" | "">("activos");
const pagina = ref(1);
const visibles = computed(() => {
  const q = buscar.value.trim().toLowerCase();
  return grupos.value.filter(
    (g) =>
      (filtroEstado.value === "" ||
        (filtroEstado.value === "activos") === g.activo) &&
      (q === "" ||
        g.nombre.toLowerCase().includes(q) ||
        (g.oferta ?? "").toLowerCase().includes(q)),
  );
});
const ultimaPagina = computed(() =>
  Math.max(1, Math.ceil(visibles.value.length / POR_PAGINA)),
);
const enPagina = computed(() =>
  visibles.value.slice(
    (pagina.value - 1) * POR_PAGINA,
    pagina.value * POR_PAGINA,
  ),
);
watch([buscar, filtroEstado], () => {
  pagina.value = 1;
});
const plantillas = ref<Plantilla[]>([]);

const cargando = ref(true);
const error = ref<string | null>(null);

const nuevo = ref({ nombre: "", plantillaId: "" });
const creando = ref(false);
const abierto = ref(false);
// Cada error donde se hizo la acción (una sola vez): crear, en su panel; inscribir,
// junto al grupo elegido. El de la página es solo el de cargar.
const errorCrear = ref<string | null>(null);
const errorDetalle = ref<string | null>(null);

function abrir(): void {
  nuevo.value = { nombre: "", plantillaId: "" };
  errorCrear.value = null;
  abierto.value = true;
}
function cerrar(): void {
  abierto.value = false;
}

const seleccionado = ref<Grupo | null>(null);
// Un grupo no tiene tope de lugares: sus inscritos se piden por páginas y se buscan.
const inscripciones = ref<Inscripcion[]>([]);
const totalInscritos = ref(0);
const buscarInscrito = ref("");
const cargandoInscritos = ref(false);
const INSCRITOS_POR_PAGINA = 20;
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
      // Un grupo nuevo sale de una serie que sigue vigente.
      api.get<{ data: Plantilla[] }>(`${base.value}/plantillas-horario`, {
        params: { estado: "vigentes" },
      }),
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
  errorCrear.value = null;
  try {
    await api.post(`${base.value}/grupos`, {
      nombre: nuevo.value.nombre,
      plantilla_id: nuevo.value.plantillaId,
    });
    abierto.value = false;
    await cargar();
  } catch (e) {
    errorCrear.value = mensajeDeError(e);
  } finally {
    creando.value = false;
  }
}

async function seleccionar(g: Grupo): Promise<void> {
  seleccionado.value = g;
  inscripciones.value = [];
  totalInscritos.value = 0;
  miembroId.value = "";
  mensaje.value = null;
  errorDetalle.value = null;
  if (buscarInscrito.value !== "") {
    // Al limpiar la búsqueda se vuelven a pedir (ver el watch).
    buscarInscrito.value = "";
    return;
  }
  await cargarInscritos(1);
}

let turnoInscritos = 0;
async function cargarInscritos(pagina: number): Promise<void> {
  const g = seleccionado.value;
  if (g === null) return;
  const mio = ++turnoInscritos;
  cargandoInscritos.value = true;
  const q = buscarInscrito.value.trim();
  try {
    const { data } = await api.get<{
      data: Inscripcion[];
      meta: { total: number };
    }>(`${base.value}/grupos/${g.id}/inscripciones`, {
      params: {
        page: pagina,
        per_page: INSCRITOS_POR_PAGINA,
        ...(q ? { q } : {}),
      },
    });
    if (mio !== turnoInscritos) return;
    inscripciones.value =
      pagina === 1 ? data.data : [...inscripciones.value, ...data.data];
    totalInscritos.value = data.meta.total;
  } catch (e) {
    if (mio === turnoInscritos) errorDetalle.value = mensajeDeError(e);
  } finally {
    if (mio === turnoInscritos) cargandoInscritos.value = false;
  }
}
function masInscritos(): void {
  void cargarInscritos(
    Math.floor(inscripciones.value.length / INSCRITOS_POR_PAGINA) + 1,
  );
}
let esperaInscrito: ReturnType<typeof setTimeout> | undefined;
watch(buscarInscrito, () => {
  clearTimeout(esperaInscrito);
  esperaInscrito = setTimeout(() => void cargarInscritos(1), 300);
});

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
  errorDetalle.value = null;
  try {
    const { data } = await api.post<{ data: { reservadas: number } }>(
      `${base.value}/grupos/${seleccionado.value.id}/inscripciones`,
      { persona_id: miembroId.value },
    );
    const n = data.data.reservadas;
    miembroId.value = "";
    await seleccionar(seleccionado.value);
    // Después de recargar el grupo (que limpia el aviso anterior).
    sinReservas.value = n === 0;
    mensaje.value =
      n === 0 ? t("cursos.sinReservadas") : t("cursos.reservadas", { n });
    await cargar();
  } catch (e) {
    errorDetalle.value = mensajeDeError(e);
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
    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
    </AvisoTono>

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
            <div class="flex flex-wrap items-center gap-2 p-3">
              <input
                v-model="buscar"
                type="search"
                class="tu-input max-w-xs"
                :placeholder="$t('operacion.listadosTres.buscarGrupo')"
                :aria-label="$t('operacion.listadosTres.buscarGrupo')"
                data-prueba="buscar-grupos"
              />
              <div
                class="tu-segmentado"
                role="group"
                data-prueba="estado-grupos"
              >
                <button
                  v-for="e in ['activos', 'inactivos', ''] as const"
                  :key="e"
                  type="button"
                  :aria-pressed="filtroEstado === e"
                  @click="filtroEstado = e"
                >
                  {{ $t(`operacion.listadosTres.estadoGrupo.${e || "todos"}`) }}
                </button>
              </div>
            </div>
            <p
              v-if="visibles.length === 0"
              class="px-4 py-8 text-center text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("operacion.listadosDos.sinResultados") }}
            </p>
            <table v-else class="tu-tabla">
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
                  v-for="g in enPagina"
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
            <PaginacionListado
              v-if="ultimaPagina > 1"
              :page="pagina"
              :ultima-pagina="ultimaPagina"
              :total="visibles.length"
              :per-page="POR_PAGINA"
              @ir="pagina = $event"
            />
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
              <p
                class="text-sm"
                :style="{ color: 'var(--texto-suave)' }"
                data-prueba="total-inscritos"
              >
                {{
                  $t(
                    "cursos.inscritosTotal",
                    { n: totalInscritos },
                    totalInscritos,
                  )
                }}
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
              <AvisoTono
                v-if="errorDetalle"
                tono="peligro"
                class="mt-3"
                role="alert"
                data-prueba="error-inscribir"
              >
                {{ errorDetalle }}
              </AvisoTono>
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

              <!-- Con muchos inscritos, se busca en vez de recorrer la lista. -->
              <input
                v-if="totalInscritos > INSCRITOS_POR_PAGINA || buscarInscrito"
                v-model="buscarInscrito"
                type="search"
                class="tu-input mt-4"
                :placeholder="$t('cursos.buscarInscrito')"
                :aria-label="$t('cursos.buscarInscrito')"
                data-prueba="buscar-inscrito"
              />
              <ul
                v-if="inscripciones.length > 0"
                class="gr-inscritos mt-4"
                :class="{ 'opacity-60': cargandoInscritos }"
              >
                <li v-for="i in inscripciones" :key="i.id">
                  <AvatarIniciales :nombre="i.persona" tam="sm" />
                  <span class="min-w-0 flex-1 truncate">{{
                    i.persona ?? "—"
                  }}</span>
                  <span
                    v-if="!i.activo"
                    class="text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ $t("cursos.inscritoBaja") }}</span
                  >
                </li>
              </ul>
              <p
                v-else-if="!cargandoInscritos"
                class="mt-4 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{
                  buscarInscrito.trim()
                    ? $t("cursos.sinInscritosBusqueda")
                    : $t("cursos.sinInscritos")
                }}
              </p>
              <button
                v-if="inscripciones.length < totalInscritos"
                type="button"
                class="tu-enlace mt-3 text-sm"
                :disabled="cargandoInscritos"
                data-prueba="mas-inscritos"
                @click="masInscritos"
              >
                {{
                  $t(
                    "cursos.verMasInscritos",
                    { n: totalInscritos - inscripciones.length },
                    totalInscritos - inscripciones.length,
                  )
                }}
              </button>
            </template>
          </aside>
        </div>
      </template>
    </template>

    <!-- Nuevo grupo (drawer lateral) -->
    <PanelLateral
      :abierto="abierto"
      :titulo="$t('cursos.nuevo')"
      :ocupado="creando"
      @cerrar="cerrar"
    >
      <form class="space-y-4" @submit.prevent="crear">
        <AvisoTono
          v-if="errorCrear"
          tono="peligro"
          role="alert"
          data-prueba="error-crear-grupo"
        >
          {{ errorCrear }}
        </AvisoTono>
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
          <button
            class="tu-btn tu-btn-fantasma"
            type="button"
            :disabled="creando"
            @click="cerrar"
          >
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
