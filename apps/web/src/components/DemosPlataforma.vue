<script setup lang="ts">
import axios from "axios";
import { onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import HorarioDemosPlataforma from "@/components/HorarioDemosPlataforma.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PestanasSeccion from "@/components/PestanasSeccion.vue";
import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import {
  opcionEnUrl,
  sinValorPorOmision,
  useFiltrosEnUrl,
  usePestanaEnUrl,
} from "@/lib/filtrosEnUrl";
import { PRODUCTOS, type Producto } from "@/lib/producto";
import { useToastStore } from "@/stores/toast";

/**
 * Superadmin › Demos (ADR 0193 y 0204): cuántas demos hay agendadas (hoy, en los
 * próximos 7 días y todas las que vienen) y cómo fueron las de los últimos 30 días;
 * debajo, las agendadas por páginas (próximas, pasadas, canceladas), con lo que contó
 * cada quien de su negocio, para llamarle y marcar cómo fue; y el horario semanal en
 * que la landing las ofrece. Sub-sección y filtros en la dirección con claves propias
 * (`demos`, `demo_ver`…): no chocan con los de otras secciones del superadmin.
 */
const props = defineProps<{ apiUrl: string; token: string }>();
const emit = defineEmits<{ proximas: [n: number] }>();

interface Calificacion {
  operando?: boolean;
  tamano?: string;
  apertura?: string;
  sucursales?: string;
  objetivos?: string[];
  giro_otro?: string;
  redes?: string;
}
interface Demo {
  id: string;
  producto: Producto;
  inicia_en: string;
  nombre: string;
  negocio: string;
  giro: string | null;
  correo: string;
  telefono: string;
  acepta_whatsapp: boolean;
  estado: "agendada" | "cancelada" | "atendida" | "no_asistio";
  calificacion: Calificacion | null;
}
interface Resumen {
  hoy: number;
  semana: number;
  proximas: number;
  atendidas: number;
  no_asistio: number;
  canceladas: number;
}
interface Meta {
  page: number;
  ultima_pagina: number;
  total: number;
  per_page: number;
  resumen: Resumen;
}

const { t } = useI18n();
const toast = useToastStore();
const cliente = () =>
  axios.create({
    baseURL: props.apiUrl,
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${props.token}`,
    },
  });

const SECCIONES = ["agendadas", "horario"] as const;
const seccion = usePestanaEnUrl(SECCIONES, { clave: "demos" });
const opcionesSeccion = SECCIONES.map((clave) => ({
  clave,
  texto: t(`demosPlataforma.pestanas.${clave}`),
}));

const VER = ["proximas", "pasadas", "canceladas", "todas"] as const;
type Ver = (typeof VER)[number];
const ver = ref<Ver>("proximas");
const producto = ref<"" | Producto>("");
const buscar = ref("");
useFiltrosEnUrl({
  demo_ver: sinValorPorOmision(opcionEnUrl(ver, VER), () => "proximas"),
  demo_producto: opcionEnUrl(producto, ["", "agendauno", "turnouno"] as const),
  demo_q: buscar,
});

const demos = ref<Demo[]>([]);
const meta = ref<Meta | null>(null);
const cargando = ref(false);
const error = ref<string | null>(null);
const cambiando = ref<string | null>(null);

const TONOS: Record<Demo["estado"], string> = {
  agendada: "var(--aviso)",
  atendida: "var(--exito)",
  no_asistio: "var(--error)",
  cancelada: "var(--texto-suave)",
};

function cuando(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "America/Mexico_City",
  }).format(new Date(iso));
}
const empezo = (d: Demo): boolean =>
  new Date(d.inicia_en).getTime() <= Date.now();

// Lo que contó de su negocio, en una o dos líneas cortas.
function conto(d: Demo): string[] {
  const c = d.calificacion;
  if (c === null) {
    return [];
  }
  const partes: string[] = [];
  if (c.giro_otro) {
    partes.push(c.giro_otro);
  }
  if (c.operando === true) {
    partes.push(t("demosPlataforma.opera"));
  }
  if (c.operando === false && c.apertura) {
    partes.push(
      t("demosPlataforma.noOpera", {
        cuando: t(`demoAgendable.opciones.apertura.${c.apertura}`),
      }),
    );
  }
  if (c.tamano) {
    partes.push(c.tamano);
  }
  if (c.sucursales) {
    partes.push(t(`demoAgendable.opciones.sucursales.${c.sucursales}`));
  }
  const lineas = partes.length > 0 ? [partes.join(" · ")] : [];
  if (c.objetivos?.length) {
    lineas.push(
      t("demosPlataforma.quiere", {
        lista: c.objetivos
          .map((o) => t(`demoAgendable.opciones.objetivos.${o}`))
          .join(", "),
      }),
    );
  }
  if (c.redes) {
    lineas.push(c.redes);
  }
  return lineas;
}

let vigente = 0;
async function cargar(pagina = 1): Promise<void> {
  const esta = ++vigente;
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await cliente().get<{ data: Demo[]; meta: Meta }>(
      "/api/v1/plataforma/demos",
      {
        params: {
          page: pagina,
          ver: ver.value,
          producto: producto.value || undefined,
          q: buscar.value.trim() || undefined,
        },
      },
    );
    if (esta !== vigente) {
      return;
    }
    demos.value = data.data;
    meta.value = data.meta;
    emit("proximas", data.meta.resumen.proximas);
  } catch (e) {
    if (esta === vigente) {
      error.value = mensajeDeError(e);
    }
  } finally {
    if (esta === vigente) {
      cargando.value = false;
    }
  }
}

// Al cambiar un filtro, de vuelta a la primera página (la búsqueda, tras una pausa).
let pausa: ReturnType<typeof setTimeout> | undefined;
watch([ver, producto], () => void cargar(1));
watch(buscar, () => {
  clearTimeout(pausa);
  pausa = setTimeout(() => void cargar(1), 300);
});

async function marcar(d: Demo, estado: Demo["estado"]): Promise<void> {
  if (
    estado === "cancelada" &&
    !(await confirmar(
      t("demosPlataforma.confirmarCancelar", {
        nombre: d.nombre,
        cuando: cuando(d.inicia_en),
      }),
      { aceptar: t("demosPlataforma.cancelar"), peligro: true },
    ))
  ) {
    return;
  }
  cambiando.value = d.id;
  try {
    await cliente().put(`/api/v1/plataforma/demos/${d.id}`, { estado });
    toast.exito(
      estado === "cancelada"
        ? t("demosPlataforma.cancelada")
        : t("demosPlataforma.marcada"),
    );
    await cargar(meta.value?.page ?? 1);
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    cambiando.value = null;
  }
}

const CIFRAS: { clave: keyof Resumen; texto: string; sub?: string }[] = [
  { clave: "hoy", texto: "demosPlataforma.resumen.hoy" },
  { clave: "semana", texto: "demosPlataforma.resumen.semana" },
  { clave: "proximas", texto: "demosPlataforma.resumen.proximas" },
  {
    clave: "atendidas",
    texto: "demosPlataforma.resumen.atendidas",
    sub: "demosPlataforma.resumen.ultimos30",
  },
  {
    clave: "no_asistio",
    texto: "demosPlataforma.resumen.noAsistio",
    sub: "demosPlataforma.resumen.ultimos30",
  },
  { clave: "canceladas", texto: "demosPlataforma.resumen.canceladas" },
];

onMounted(() => void cargar());
</script>

<template>
  <div data-prueba="demos-plataforma">
    <!-- Cuántas hay: lo primero que se busca al entrar. -->
    <section
      class="tu-card dp-cifras"
      :aria-label="t('demosPlataforma.resumen.etiqueta')"
      data-prueba="demos-resumen"
    >
      <div v-for="c in CIFRAS" :key="c.clave" :data-cifra="c.clave">
        <p class="dp-etiqueta">{{ t(c.texto) }}</p>
        <p class="dp-numero">{{ meta?.resumen[c.clave] ?? "—" }}</p>
        <p v-if="c.sub" class="tu-sub">{{ t(c.sub) }}</p>
      </div>
    </section>

    <PestanasSeccion
      v-model="seccion"
      :opciones="opcionesSeccion"
      :etiqueta="t('demosPlataforma.pestanas.etiqueta')"
    />

    <section
      v-if="seccion === 'agendadas'"
      class="tu-card mt-4 overflow-hidden"
      data-prueba="demos-agendadas"
    >
      <div class="tu-filtros">
        <label class="tu-buscar min-w-[14rem] flex-1">
          <span class="sr-only">{{ t("demosPlataforma.filtros.buscar") }}</span>
          <input
            v-model="buscar"
            type="search"
            class="tu-input"
            :placeholder="t('demosPlataforma.filtros.buscar')"
            data-prueba="demos-buscar"
          />
        </label>
        <label>
          <span class="sr-only">{{ t("demosPlataforma.filtros.ver") }}</span>
          <select v-model="ver" class="tu-input w-auto" data-prueba="demos-ver">
            <option v-for="v in VER" :key="v" :value="v">
              {{ t(`demosPlataforma.filtros.verOpciones.${v}`) }}
            </option>
          </select>
        </label>
        <label>
          <span class="sr-only">{{
            t("demosPlataforma.filtros.producto")
          }}</span>
          <select
            v-model="producto"
            class="tu-input w-auto"
            data-prueba="demos-producto"
          >
            <option value="">{{ t("demosPlataforma.filtros.todos") }}</option>
            <option
              v-for="p in ['agendauno', 'turnouno'] as const"
              :key="p"
              :value="p"
            >
              {{ PRODUCTOS[p].nombre }}
            </option>
          </select>
        </label>
      </div>

      <AvisoTono v-if="error" tono="peligro" class="m-4" role="alert">
        {{ error }}
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico ml-2"
          @click="cargar(meta?.page ?? 1)"
        >
          {{ t("comun.reintentar") }}
        </button>
      </AvisoTono>
      <p
        v-else-if="!cargando && demos.length === 0"
        class="p-5 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{
          ver === "proximas" && !producto && !buscar.trim()
            ? t("demosPlataforma.vacio")
            : t("demosPlataforma.vacioFiltro")
        }}
      </p>
      <!-- Tabla en la computadora, ficha en el teléfono, con las acciones a la vista. -->
      <div
        v-else
        class="relative overflow-x-auto tu-tabla-adaptable"
        :aria-busy="cargando || undefined"
      >
        <table class="tu-tabla text-sm">
          <thead>
            <tr>
              <th>{{ t("demosPlataforma.quien") }}</th>
              <th>{{ t("demosPlataforma.cuando") }}</th>
              <th>{{ t("demosPlataforma.contacto") }}</th>
              <th>{{ t("demosPlataforma.conto") }}</th>
              <th>{{ t("demosPlataforma.estado") }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in demos" :key="d.id" :data-demo="d.id">
              <td :data-etiqueta="t('demosPlataforma.quien')">
                {{ d.nombre }}
                <span class="tu-sub"
                  >{{ d.negocio }} ·
                  {{ PRODUCTOS[d.producto]?.nombre ?? d.producto }}</span
                >
              </td>
              <td
                class="whitespace-nowrap"
                :data-etiqueta="t('demosPlataforma.cuando')"
              >
                {{ cuando(d.inicia_en) }}
              </td>
              <td :data-etiqueta="t('demosPlataforma.contacto')">
                <!-- Un solo bloque: en la ficha del teléfono, todo junto al valor. -->
                <div>
                  {{ d.telefono }}
                  <span class="tu-sub">{{ d.correo }}</span>
                </div>
              </td>
              <td :data-etiqueta="t('demosPlataforma.conto')">
                <div v-if="conto(d).length > 0" class="dp-conto">
                  <span
                    v-for="(linea, i) in conto(d)"
                    :key="i"
                    :class="i === 0 ? 'block' : 'tu-sub'"
                    >{{ linea }}</span
                  >
                </div>
                <span v-else :style="{ color: 'var(--texto-suave)' }">—</span>
              </td>
              <td :data-etiqueta="t('demosPlataforma.estado')">
                <span
                  class="tu-estado"
                  :style="{ '--tono': TONOS[d.estado] }"
                  >{{ t(`demosPlataforma.estados.${d.estado}`) }}</span
                >
              </td>
              <td class="tu-celda-acciones">
                <template v-if="d.estado === 'agendada'">
                  <!-- Ya empezó: se marca cómo fue. Aún no: se puede cancelar. -->
                  <template v-if="empezo(d)">
                    <button
                      type="button"
                      class="tu-btn tu-btn-secundario tu-btn-chico"
                      :disabled="cambiando === d.id"
                      :aria-busy="cambiando === d.id || undefined"
                      @click="marcar(d, 'atendida')"
                    >
                      {{ t("demosPlataforma.atendida") }}
                    </button>
                    <button
                      type="button"
                      class="tu-btn tu-btn-secundario tu-btn-chico"
                      :disabled="cambiando === d.id"
                      @click="marcar(d, 'no_asistio')"
                    >
                      {{ t("demosPlataforma.noAsistio") }}
                    </button>
                  </template>
                  <button
                    v-else
                    type="button"
                    class="tu-btn tu-btn-peligro tu-btn-chico"
                    :disabled="cambiando === d.id"
                    :aria-busy="cambiando === d.id || undefined"
                    :data-prueba="`cancelar-demo-${d.id}`"
                    @click="marcar(d, 'cancelada')"
                  >
                    {{ t("demosPlataforma.cancelar") }}
                  </button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginacionListado
        v-if="meta && meta.ultima_pagina > 1"
        :page="meta.page"
        :ultima-pagina="meta.ultima_pagina"
        :total="meta.total"
        :per-page="meta.per_page"
        @ir="cargar"
      />
    </section>

    <HorarioDemosPlataforma
      v-else
      class="mt-4"
      :api-url="apiUrl"
      :token="token"
      @guardado="cargar(meta?.page ?? 1)"
    />
  </div>
</template>

<style scoped>
/* Las cifras en una franja: tres por renglón en la computadora, dos en el teléfono. */
.dp-cifras {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem 1.5rem;
  padding: 1rem 1.25rem;
}
@media (min-width: 640px) {
  .dp-cifras {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
@media (min-width: 1024px) {
  .dp-cifras {
    grid-template-columns: repeat(6, minmax(0, 1fr));
  }
}
.dp-etiqueta {
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.dp-numero {
  margin-top: 0.15rem;
  font-size: 1.5rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.dp-conto {
  max-width: 18rem;
}
</style>
