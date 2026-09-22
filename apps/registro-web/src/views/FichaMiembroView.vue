<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import PanelEditarMiembro, {
  type MiembroEditable,
} from "@/components/PanelEditarMiembro.vue";
import PanelMiembro from "@/components/PanelMiembro.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

// El resumen operativo (membresía/saldo/adeudo/alertas/próxima) lo entrega el mismo
// endpoint que usa Recepción; la ficha añade el historial (derechos/reservas/compras).
interface Resumen {
  nombre_completo: string;
  email: string | null;
  saldo_creditos: number;
  membresia: { estado: string; valido_hasta: string | null };
  adeudo: boolean;
  documentos_pendientes: number;
  asistencias: number;
  primera_vez: boolean;
  proxima_reserva: {
    clase: string | null;
    inicia_en: string;
    zona_horaria: string | null;
  } | null;
  alertas: string[];
}
interface Derecho {
  id: string;
  producto: string | null;
  estado: string | null;
  ilimitado: boolean;
  saldo_creditos: number | null;
  saldo_unidades: number | null;
  disponible_unidades: number | null;
  valido_hasta: string | null;
}
interface Reserva {
  id: string;
  clase: string | null;
  inicia_en: string | null;
  zona_horaria: string | null;
  estado: string;
  asistencia: string | null;
}
interface Orden {
  id: string;
  fecha: string | null;
  estado: string;
  total_minor: number;
  moneda: string;
  metodo_pago: string | null;
  pagada_en: string | null;
}
interface Ficha {
  persona: {
    id: string;
    nombre_completo: string;
    email: string | null;
    tipo: string;
    activo: boolean;
    es_facturable: boolean;
    archivado: boolean;
    alta: string | null;
    sucursal: string | null;
  };
  derechos: Derecho[];
  reservas: Reserva[];
  ordenes: Orden[];
}

const route = useRoute();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const personaId = computed(() => String(route.params.id));
const puedeGestionar = computed(() => sesion.puede("miembros.gestionar"));
const puedeVender = computed(() => sesion.puede("ordenes.gestionar"));

const resumen = ref<Resumen | null>(null);
const ficha = ref<Ficha | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const editando = ref<MiembroEditable | null>(null);
const vendiendo = ref(false);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
function fechaHora(iso: string | null, zona: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: zona ?? undefined,
  }).format(new Date(iso));
}
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

// Chip ámbar para "por vencer"; rojo para el resto de alertas.
function estiloAlerta(codigo: string): Record<string, string> {
  return codigo === "membresia_por_vencer"
    ? { background: "var(--aviso-suave)", color: "var(--aviso)" }
    : { background: "var(--error-suave)", color: "var(--error)" };
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [r, f] = await Promise.all([
      api.get<{ data: Resumen }>(
        `${base.value}/miembros/${personaId.value}/resumen`,
      ),
      api.get<{ data: Ficha }>(
        `${base.value}/miembros/${personaId.value}/ficha`,
      ),
    ]);
    resumen.value = r.data.data;
    ficha.value = f.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function abrirEditar(): void {
  if (ficha.value === null) {
    return;
  }
  const p = ficha.value.persona;
  editando.value = {
    id: p.id,
    nombre: p.nombre_completo,
    segundo_nombre: null,
    primer_apellido: null,
    segundo_apellido: null,
    email: p.email,
    activo: p.activo,
    es_facturable: p.es_facturable,
    archivado: p.archivado,
  };
}
function onGuardado(): void {
  editando.value = null;
  void cargar();
}
function onCerrarVenta(): void {
  vendiendo.value = false;
  void cargar();
}

watch(personaId, cargar, { immediate: true });
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 py-8">
    <RouterLink :to="{ name: 'miembros' }" class="tu-enlace text-sm"
      >← {{ $t("ficha.volver") }}</RouterLink
    >

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-if="cargando"
      class="tu-card mt-4 p-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else-if="ficha && resumen">
      <!-- Encabezado del alumno -->
      <header class="mt-4 tu-card p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="flex items-center gap-4 min-w-0">
            <span
              class="h-14 w-14 rounded-full inline-flex items-center justify-center text-xl font-bold text-white shrink-0"
              :style="{ background: 'var(--primario)' }"
              aria-hidden="true"
              >{{ ficha.persona.nombre_completo.charAt(0).toUpperCase() }}</span
            >
            <div class="min-w-0">
              <h1 class="text-2xl font-extrabold truncate">
                {{ ficha.persona.nombre_completo }}
              </h1>
              <p
                v-if="ficha.persona.email"
                class="text-sm truncate"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ ficha.persona.email }}
              </p>
              <div class="mt-2 flex flex-wrap gap-1.5">
                <span
                  class="tu-badge"
                  :class="
                    ficha.persona.activo ? 'tu-badge-exito' : 'tu-badge-aviso'
                  "
                  >{{
                    ficha.persona.activo
                      ? $t("miembros.activo")
                      : $t("miembros.suspendido")
                  }}</span
                >
                <span v-if="!ficha.persona.es_facturable" class="tu-badge">{{
                  $t("miembros.noFacturable")
                }}</span>
                <span v-if="ficha.persona.archivado" class="tu-badge">{{
                  $t("miembros.archivado")
                }}</span>
              </div>
            </div>
          </div>
          <div class="flex gap-2">
            <button
              v-if="puedeVender"
              type="button"
              class="tu-btn tu-btn-primario text-sm"
              @click="vendiendo = true"
            >
              {{ $t("ficha.vender") }}
            </button>
            <button
              v-if="puedeGestionar"
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              @click="abrirEditar"
            >
              {{ $t("ficha.editar") }}
            </button>
          </div>
        </div>

        <!-- Alertas -->
        <div
          v-if="resumen.alertas.length > 0"
          class="mt-4 flex flex-wrap gap-2"
        >
          <span
            v-for="a in resumen.alertas"
            :key="a"
            class="tu-badge"
            :style="estiloAlerta(a)"
            >{{ $t(`recepcion.alertas.${a}`) }}</span
          >
        </div>

        <!-- Datos meta -->
        <dl
          class="mt-4 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4"
        >
          <div>
            <dt :style="{ color: 'var(--texto-suave)' }">
              {{ $t("ficha.alta") }}
            </dt>
            <dd class="font-medium">{{ fecha(ficha.persona.alta) }}</dd>
          </div>
          <div v-if="ficha.persona.sucursal">
            <dt :style="{ color: 'var(--texto-suave)' }">
              {{ $t("ficha.sucursal") }}
            </dt>
            <dd class="font-medium">{{ ficha.persona.sucursal }}</dd>
          </div>
          <div>
            <dt :style="{ color: 'var(--texto-suave)' }">
              {{ $t("ficha.asistencias") }}
            </dt>
            <dd class="font-medium">
              {{ resumen.asistencias }}
              <span
                v-if="resumen.primera_vez"
                class="tu-badge tu-badge-aviso ml-1"
                >{{ $t("miembros.nuevo") }}</span
              >
            </dd>
          </div>
          <div v-if="resumen.documentos_pendientes > 0">
            <dt :style="{ color: 'var(--texto-suave)' }">
              {{ $t("ficha.documentos") }}
            </dt>
            <dd class="font-medium" :style="{ color: 'var(--aviso)' }">
              {{ resumen.documentos_pendientes }}
            </dd>
          </div>
        </dl>
      </header>

      <!-- Tarjetas resumen -->
      <div class="mt-4 grid gap-4 sm:grid-cols-3">
        <div class="tu-card p-4">
          <p
            class="text-xs uppercase tracking-wide"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("ficha.membresia") }}
          </p>
          <p class="mt-1 text-lg font-bold">
            {{ $t(`recepcion.membresia.${resumen.membresia.estado}`) }}
          </p>
          <p
            v-if="resumen.membresia.valido_hasta"
            class="text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t("ficha.derechos.vence", {
                fecha: fecha(resumen.membresia.valido_hasta),
              })
            }}
          </p>
        </div>
        <div class="tu-card p-4">
          <p
            class="text-xs uppercase tracking-wide"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("ficha.saldo") }}
          </p>
          <p class="mt-1 text-lg font-bold">
            {{ $t("ficha.creditos", { n: resumen.saldo_creditos }) }}
          </p>
        </div>
        <div class="tu-card p-4">
          <p
            class="text-xs uppercase tracking-wide"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("ficha.proxima") }}
          </p>
          <template v-if="resumen.proxima_reserva">
            <p class="mt-1 text-lg font-bold truncate">
              {{ resumen.proxima_reserva.clase ?? "—" }}
            </p>
            <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{
                fechaHora(
                  resumen.proxima_reserva.inicia_en,
                  resumen.proxima_reserva.zona_horaria,
                )
              }}
            </p>
          </template>
          <p
            v-else
            class="mt-1 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("ficha.sinProxima") }}
          </p>
        </div>
      </div>

      <!-- Membresías y paquetes (derechos) -->
      <section class="mt-6">
        <h2 class="font-bold">{{ $t("ficha.derechos.titulo") }}</h2>
        <p
          v-if="ficha.derechos.length === 0"
          class="tu-card mt-3 p-4 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("ficha.derechos.vacio") }}
        </p>
        <ul v-else class="mt-3 space-y-2">
          <li
            v-for="d in ficha.derechos"
            :key="d.id"
            class="tu-card flex flex-wrap items-center justify-between gap-3 p-4"
          >
            <div class="min-w-0">
              <p class="font-semibold truncate">{{ d.producto ?? "—" }}</p>
              <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                <span
                  v-if="d.estado"
                  class="tu-badge mr-2"
                  :class="
                    d.estado === 'activo' ? 'tu-badge-exito' : 'tu-badge-aviso'
                  "
                  >{{ $t(`ficha.acuerdo.${d.estado}`) }}</span
                >
                <span>{{
                  d.valido_hasta
                    ? $t("ficha.derechos.vence", {
                        fecha: fecha(d.valido_hasta),
                      })
                    : $t("ficha.derechos.sinVence")
                }}</span>
              </p>
            </div>
            <div class="text-right shrink-0">
              <template v-if="d.ilimitado">
                <span class="tu-badge tu-badge-exito">{{
                  $t("ficha.derechos.ilimitado")
                }}</span>
              </template>
              <template v-else>
                <p class="font-bold">
                  {{ $t("ficha.creditos", { n: d.saldo_creditos ?? 0 }) }}
                </p>
                <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                  {{
                    $t("ficha.derechos.disponible", {
                      n: (d.disponible_unidades ?? 0) / 1000,
                    })
                  }}
                </p>
              </template>
            </div>
          </li>
        </ul>
      </section>

      <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <!-- Historial de reservas -->
        <section>
          <h2 class="font-bold">{{ $t("ficha.reservas.titulo") }}</h2>
          <p
            v-if="ficha.reservas.length === 0"
            class="tu-card mt-3 p-4 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("ficha.reservas.vacio") }}
          </p>
          <ul v-else class="mt-3 tu-card overflow-hidden">
            <li
              v-for="(r, i) in ficha.reservas"
              :key="r.id"
              class="flex items-center justify-between gap-3 px-4 py-3"
              :class="i > 0 ? 'border-t' : ''"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <div class="min-w-0">
                <p class="font-medium truncate">{{ r.clase ?? "—" }}</p>
                <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                  {{ fechaHora(r.inicia_en, r.zona_horaria) }}
                </p>
              </div>
              <div class="flex items-center gap-1.5 shrink-0">
                <span
                  v-if="r.asistencia"
                  class="tu-badge"
                  :class="
                    r.asistencia === 'presente'
                      ? 'tu-badge-exito'
                      : 'tu-badge-aviso'
                  "
                  >{{ $t(`agenda.roster.${r.asistencia}`) }}</span
                >
                <span v-else class="tu-badge">{{
                  $t(`agenda.roster.${r.estado}`)
                }}</span>
              </div>
            </li>
          </ul>
        </section>

        <!-- Historial de compras -->
        <section>
          <h2 class="font-bold">{{ $t("ficha.ordenes.titulo") }}</h2>
          <p
            v-if="ficha.ordenes.length === 0"
            class="tu-card mt-3 p-4 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("ficha.ordenes.vacio") }}
          </p>
          <ul v-else class="mt-3 tu-card overflow-hidden">
            <li
              v-for="(o, i) in ficha.ordenes"
              :key="o.id"
              class="flex items-center justify-between gap-3 px-4 py-3"
              :class="i > 0 ? 'border-t' : ''"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <div class="min-w-0">
                <p class="font-medium">{{ dinero(o.total_minor, o.moneda) }}</p>
                <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                  {{ fecha(o.fecha)
                  }}<span v-if="o.metodo_pago"> · {{ o.metodo_pago }}</span>
                </p>
              </div>
              <span
                class="tu-badge shrink-0"
                :class="
                  o.estado === 'pagada' ? 'tu-badge-exito' : 'tu-badge-aviso'
                "
                >{{ $t(`ficha.ordenes.estados.${o.estado}`) }}</span
              >
            </li>
          </ul>
        </section>
      </div>
    </template>

    <PanelEditarMiembro
      v-if="editando"
      :miembro="editando"
      @cerrar="editando = null"
      @guardado="onGuardado"
    />
    <PanelMiembro
      v-if="vendiendo && ficha"
      :persona-id="personaId"
      :nombre="ficha.persona.nombre_completo"
      @cerrar="onCerrarVenta"
    />
  </section>
</template>
