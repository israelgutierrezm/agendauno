<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute, useRouter } from "vue-router";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import ExpedientePersona from "@/components/ExpedientePersona.vue";
import PanelEditarMiembro, {
  type MiembroEditable,
} from "@/components/PanelEditarMiembro.vue";
import PanelMiembro from "@/components/PanelMiembro.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

// El resumen operativo (membresía/saldo/adeudo/alertas/próxima) lo entrega el mismo
// endpoint que usa Recepción; la ficha añade el historial (derechos/reservas/compras).
interface Resumen {
  nombre_completo: string;
  email: string | null;
  saldo_creditos: number;
  membresia: {
    estado: string;
    valido_hasta: string | null;
    pausada_hasta?: string | null;
  };
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
  // Para pausar/reanudar la membresía a la que pertenece.
  acuerdo_id: string | null;
  pausa_hasta: string | null;
}
interface Movimiento {
  id: string;
  tipo: string;
  unidades: number;
  saldo_posterior: number;
  descripcion: string | null;
  // Por qué cambió el saldo, en palabras ("Asistencia", "Cancelación tardía"…).
  concepto?: string;
  origen?: string | null;
  actor: string | null;
  fecha: string | null;
}
interface Reserva {
  id: string;
  clase: string | null;
  inicia_en: string | null;
  zona_horaria: string | null;
  estado: string;
  asistencia: string | null;
  cancelada_por?: "cliente" | "negocio" | "sistema" | null;
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

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
// Actividad (membresías, reservas, compras) o Expediente (documentos, formularios,
// consentimientos). Va en la URL (?seccion=expediente) para poder enlazarla.
const seccion = computed(() =>
  route.query.seccion === "expediente" ? "expediente" : "actividad",
);
function irSeccion(s: "actividad" | "expediente"): void {
  void router.replace({
    query: { ...route.query, seccion: s === "actividad" ? undefined : s },
  });
}
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const personaId = computed(() => String(route.params.id));
const puedeGestionar = computed(() => sesion.puede("miembros.gestionar"));
const puedeVender = computed(() => sesion.puede("ordenes.gestionar"));
const puedeRecargar = computed(() => sesion.puede("membresias.gestionar"));
const toast = useToastStore();

// Movimientos del ledger de un derecho (1000 unidades = 1 crédito) y recarga manual.
const movimientosDe = ref<string | null>(null);
const movimientos = ref<Movimiento[]>([]);
const recargando = ref<string | null>(null);
const recarga = ref({ creditos: 1, motivo: "" });
const guardandoRecarga = ref(false);

function creditos(unidades: number): string {
  return (unidades / 1000).toLocaleString("es-MX", {
    maximumFractionDigits: 3,
  });
}

async function verMovimientos(d: Derecho): Promise<void> {
  if (movimientosDe.value === d.id) {
    movimientosDe.value = null;
    return;
  }
  try {
    const { data } = await api.get<{ data: Movimiento[] }>(
      `${base.value}/derechos/${d.id}/movimientos`,
    );
    movimientos.value = data.data;
    movimientosDe.value = d.id;
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

function abrirRecarga(d: Derecho): void {
  recarga.value = { creditos: 1, motivo: "" };
  recargando.value = recargando.value === d.id ? null : d.id;
}

async function recargar(d: Derecho): Promise<void> {
  guardandoRecarga.value = true;
  try {
    await api.post(`${base.value}/derechos/${d.id}/topups`, {
      unidades: Math.round(recarga.value.creditos * 1000),
      descripcion: recarga.value.motivo,
    });
    toast.exito(t("creditosFicha.agregados"));
    recargando.value = null;
    movimientosDe.value = null;
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardandoRecarga.value = false;
  }
}

// Pausar (congelar) la membresía por vacaciones, lesión, etc.
const pausando = ref<string | null>(null);
const pausa = ref({ hasta: "", motivo: "" });
const guardandoPausa = ref(false);
const hoy = new Date().toLocaleDateString("en-CA");

function abrirPausa(d: Derecho): void {
  pausa.value = { hasta: "", motivo: "" };
  pausando.value = pausando.value === d.id ? null : d.id;
}

async function pausar(d: Derecho): Promise<void> {
  guardandoPausa.value = true;
  try {
    await api.post(`${base.value}/acuerdos/${d.acuerdo_id}/pausar`, {
      hasta: pausa.value.hasta,
      motivo: pausa.value.motivo || null,
    });
    toast.exito(
      t("pausaMembresia.pausada", { fecha: fecha(pausa.value.hasta) }),
    );
    pausando.value = null;
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardandoPausa.value = false;
  }
}

async function reanudar(d: Derecho): Promise<void> {
  guardandoPausa.value = true;
  try {
    await api.post(`${base.value}/acuerdos/${d.acuerdo_id}/reanudar`);
    toast.exito(t("pausaMembresia.reanudada"));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardandoPausa.value = false;
  }
}

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

// Ámbar para "por vencer"; rojo para el resto de alertas.
function colorAlerta(codigo: string): string {
  return codigo === "membresia_por_vencer" || codigo === "membresia_pausada"
    ? "var(--aviso)"
    : "var(--error)";
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
      <!-- Una sola tarjeta (como la demo de la landing): la cabecera arriba, el
           historial a la izquierda y el resumen a un lado sobre fondo gris. -->
      <div class="mt-4 tu-card overflow-hidden">
        <header
          class="flex flex-wrap items-start justify-between gap-4 p-5 border-b"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <div class="flex items-center gap-4 min-w-0">
            <AvatarIniciales :nombre="ficha.persona.nombre_completo" tam="lg" />
            <div class="min-w-0">
              <h1 class="text-xl font-semibold tracking-tight truncate">
                {{ ficha.persona.nombre_completo }}
              </h1>
              <p
                v-if="ficha.persona.email"
                class="text-sm truncate"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ ficha.persona.email }}
              </p>
              <p
                class="mt-1 flex flex-wrap items-center gap-1.5 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
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
                <span v-if="!ficha.persona.es_facturable"
                  >· {{ $t("miembros.noFacturable") }}</span
                >
                <span v-if="ficha.persona.archivado"
                  >· {{ $t("miembros.archivado") }}</span
                >
              </p>
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
        </header>

        <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_20rem]">
          <div class="min-w-0">
            <div class="px-5 pt-5">
              <div class="tu-segmentado" role="group">
                <button
                  type="button"
                  :aria-pressed="seccion === 'actividad'"
                  @click="irSeccion('actividad')"
                >
                  {{ $t("expediente.actividad") }}
                </button>
                <button
                  type="button"
                  :aria-pressed="seccion === 'expediente'"
                  @click="irSeccion('expediente')"
                >
                  {{ $t("expediente.titulo") }}
                </button>
              </div>
            </div>

            <ExpedientePersona
              v-if="seccion === 'expediente'"
              :persona-id="ficha.persona.id"
              tipo-persona="miembro"
            />
            <template v-else>
              <!-- Membresías y paquetes (derechos) -->
              <section class="px-5 py-5">
                <h2 class="text-sm font-semibold">
                  {{ $t("ficha.derechos.titulo") }}
                </h2>
                <p
                  v-if="ficha.derechos.length === 0"
                  class="mt-2 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("ficha.derechos.vacio") }}
                </p>
                <ul v-else class="mt-1">
                  <li
                    v-for="d in ficha.derechos"
                    :key="d.id"
                    class="fi-fila"
                    :style="{ borderColor: 'var(--borde)' }"
                  >
                    <div class="min-w-0">
                      <p class="font-medium truncate">
                        {{ d.producto ?? "—" }}
                      </p>
                      <p
                        class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        <span
                          v-if="d.estado"
                          class="tu-badge"
                          :class="
                            d.estado === 'activo'
                              ? 'tu-badge-exito'
                              : 'tu-badge-aviso'
                          "
                          >{{ $t(`ficha.acuerdo.${d.estado}`) }}</span
                        >
                        <span v-if="d.pausa_hasta">{{
                          $t("pausaMembresia.enPausa", {
                            fecha: fecha(d.pausa_hasta),
                          })
                        }}</span>
                        <span v-else>{{
                          d.valido_hasta
                            ? $t("ficha.derechos.vence", {
                                fecha: fecha(d.valido_hasta),
                              })
                            : $t("ficha.derechos.sinVence")
                        }}</span>
                      </p>
                    </div>
                    <div class="text-right shrink-0">
                      <p v-if="d.ilimitado" class="text-sm font-medium">
                        {{ $t("ficha.derechos.ilimitado") }}
                      </p>
                      <template v-else>
                        <p class="font-semibold tabular-nums">
                          {{
                            $t("ficha.creditos", { n: d.saldo_creditos ?? 0 })
                          }}
                        </p>
                        <p
                          class="text-xs"
                          :style="{ color: 'var(--texto-suave)' }"
                        >
                          {{
                            $t("ficha.derechos.disponible", {
                              n: (d.disponible_unidades ?? 0) / 1000,
                            })
                          }}
                        </p>
                      </template>
                    </div>
                    <div
                      v-if="
                        !d.ilimitado ||
                        (puedeRecargar &&
                          d.acuerdo_id &&
                          (d.estado === 'activo' || d.estado === 'pausado'))
                      "
                      class="flex w-full items-center gap-4 text-sm"
                    >
                      <template v-if="!d.ilimitado">
                        <button
                          type="button"
                          class="tu-enlace"
                          :aria-expanded="movimientosDe === d.id"
                          @click="verMovimientos(d)"
                        >
                          {{
                            movimientosDe === d.id
                              ? $t("creditosFicha.ocultar")
                              : $t("creditosFicha.movimientos")
                          }}
                        </button>
                        <button
                          v-if="puedeRecargar"
                          type="button"
                          class="tu-enlace"
                          @click="abrirRecarga(d)"
                        >
                          {{ $t("creditosFicha.agregar") }}
                        </button>
                      </template>
                      <template v-if="puedeRecargar && d.acuerdo_id">
                        <button
                          v-if="d.estado === 'activo'"
                          type="button"
                          class="tu-enlace"
                          :aria-expanded="pausando === d.id"
                          @click="abrirPausa(d)"
                        >
                          {{ $t("pausaMembresia.pausar") }}
                        </button>
                        <button
                          v-else-if="d.estado === 'pausado'"
                          type="button"
                          class="tu-enlace"
                          :disabled="guardandoPausa"
                          @click="reanudar(d)"
                        >
                          {{ $t("pausaMembresia.reanudar") }}
                        </button>
                      </template>
                    </div>
                    <form
                      v-if="pausando === d.id"
                      class="w-full space-y-3 rounded-xl border p-4"
                      :style="{
                        borderColor: 'var(--borde)',
                        background: 'var(--fondo)',
                      }"
                      @submit.prevent="pausar(d)"
                    >
                      <p
                        class="text-xs"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        {{ $t("pausaMembresia.ayuda") }}
                      </p>
                      <div class="flex flex-wrap items-end gap-3">
                        <div>
                          <label class="tu-label" :for="`ph-${d.id}`">{{
                            $t("pausaMembresia.hasta")
                          }}</label>
                          <input
                            :id="`ph-${d.id}`"
                            v-model="pausa.hasta"
                            class="tu-input"
                            type="date"
                            :min="hoy"
                            required
                          />
                        </div>
                        <div class="min-w-[12rem] flex-1">
                          <label class="tu-label" :for="`pm-${d.id}`">{{
                            $t("pausaMembresia.motivo")
                          }}</label>
                          <input
                            :id="`pm-${d.id}`"
                            v-model="pausa.motivo"
                            class="tu-input"
                            maxlength="255"
                            :placeholder="$t('pausaMembresia.motivoPh')"
                          />
                        </div>
                        <button
                          type="submit"
                          class="tu-btn tu-btn-primario text-sm"
                          :disabled="guardandoPausa || pausa.hasta === ''"
                        >
                          {{ $t("pausaMembresia.confirmar") }}
                        </button>
                      </div>
                    </form>
                    <form
                      v-if="recargando === d.id"
                      class="flex w-full flex-wrap items-end gap-3 rounded-xl border p-4"
                      :style="{
                        borderColor: 'var(--borde)',
                        background: 'var(--fondo)',
                      }"
                      @submit.prevent="recargar(d)"
                    >
                      <div>
                        <label class="tu-label" :for="`rc-${d.id}`">{{
                          $t("creditosFicha.creditos")
                        }}</label>
                        <input
                          :id="`rc-${d.id}`"
                          v-model.number="recarga.creditos"
                          class="tu-input w-24"
                          type="number"
                          min="0.5"
                          step="0.5"
                          required
                        />
                      </div>
                      <div class="min-w-[12rem] flex-1">
                        <label class="tu-label" :for="`rm-${d.id}`">{{
                          $t("creditosFicha.motivo")
                        }}</label>
                        <input
                          :id="`rm-${d.id}`"
                          v-model="recarga.motivo"
                          class="tu-input"
                          maxlength="255"
                          :placeholder="$t('creditosFicha.motivoPh')"
                          required
                        />
                      </div>
                      <button
                        type="submit"
                        class="tu-btn tu-btn-primario text-sm"
                        :disabled="guardandoRecarga || recarga.creditos <= 0"
                      >
                        {{ $t("creditosFicha.confirmar") }}
                      </button>
                    </form>
                    <div
                      v-if="movimientosDe === d.id"
                      class="w-full rounded-xl border px-4 text-xs"
                      :style="{
                        borderColor: 'var(--borde)',
                        background: 'var(--fondo)',
                      }"
                    >
                      <p
                        v-if="movimientos.length === 0"
                        class="py-3"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        {{ $t("creditosFicha.sinMovimientos") }}
                      </p>
                      <div
                        v-for="m in movimientos"
                        :key="m.id"
                        class="flex items-center justify-between gap-3 border-t py-2 first:border-t-0"
                        :style="{ borderColor: 'var(--borde)' }"
                      >
                        <span class="min-w-0">
                          <span class="block font-medium">{{
                            m.concepto ?? $t(`creditosFicha.tipos.${m.tipo}`)
                          }}</span>
                          <span
                            class="block truncate"
                            :style="{ color: 'var(--texto-suave)' }"
                            >{{ fecha(m.fecha) }}
                            <!-- La nota solo en ajustes a mano (el resto ya lo dice el concepto). -->
                            <template
                              v-if="m.descripcion && m.origen === 'ajuste'"
                            >
                              · {{ m.descripcion }}</template
                            >
                            <template v-if="m.actor">
                              ·
                              {{
                                $t("creditosFicha.por", { actor: m.actor })
                              }}</template
                            ></span
                          >
                        </span>
                        <span class="shrink-0 text-right tabular-nums">
                          <span
                            class="block font-semibold"
                            :style="{
                              color:
                                m.unidades < 0
                                  ? 'var(--error)'
                                  : 'var(--exito)',
                            }"
                            >{{ m.unidades > 0 ? "+" : ""
                            }}{{ creditos(m.unidades) }}</span
                          >
                          <span :style="{ color: 'var(--texto-suave)' }">{{
                            $t("creditosFicha.saldo", {
                              n: creditos(m.saldo_posterior),
                            })
                          }}</span>
                        </span>
                      </div>
                    </div>
                  </li>
                </ul>
              </section>

              <!-- Historial de reservas -->
              <section
                class="px-5 py-5 border-t"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <h2 class="text-sm font-semibold">
                  {{ $t("ficha.reservas.titulo") }}
                </h2>
                <p
                  v-if="ficha.reservas.length === 0"
                  class="mt-2 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("ficha.reservas.vacio") }}
                </p>
                <ul v-else class="mt-1">
                  <li
                    v-for="r in ficha.reservas"
                    :key="r.id"
                    class="fi-fila"
                    :style="{ borderColor: 'var(--borde)' }"
                  >
                    <div class="min-w-0">
                      <p class="font-medium truncate">{{ r.clase ?? "—" }}</p>
                      <p
                        class="mt-0.5 text-xs"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        {{ fechaHora(r.inicia_en, r.zona_horaria) }}
                      </p>
                    </div>
                    <span
                      v-if="r.asistencia === 'presente'"
                      class="tu-badge tu-badge-exito shrink-0"
                      >{{ $t("agenda.roster.presente") }}</span
                    >
                    <span
                      v-else
                      class="text-xs shrink-0"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{ $t(`agenda.roster.${r.asistencia ?? r.estado}`)
                      }}<template v-if="r.cancelada_por">
                        ·
                        {{
                          $t(`cancelacion.canceladaPor.${r.cancelada_por}`)
                        }}</template
                      ></span
                    >
                  </li>
                </ul>
              </section>

              <!-- Historial de compras -->
              <section
                class="px-5 py-5 border-t"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <h2 class="text-sm font-semibold">
                  {{ $t("ficha.ordenes.titulo") }}
                </h2>
                <p
                  v-if="ficha.ordenes.length === 0"
                  class="mt-2 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("ficha.ordenes.vacio") }}
                </p>
                <ul v-else class="mt-1">
                  <li
                    v-for="o in ficha.ordenes"
                    :key="o.id"
                    class="fi-fila"
                    :style="{ borderColor: 'var(--borde)' }"
                  >
                    <div class="min-w-0">
                      <p class="font-medium tabular-nums">
                        {{ dinero(o.total_minor, o.moneda) }}
                      </p>
                      <p
                        class="mt-0.5 text-xs"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        {{ fecha(o.fecha)
                        }}<span v-if="o.metodo_pago">
                          · {{ o.metodo_pago }}</span
                        >
                      </p>
                    </div>
                    <span
                      class="tu-badge shrink-0"
                      :class="
                        o.estado === 'pagada'
                          ? 'tu-badge-exito'
                          : 'tu-badge-aviso'
                      "
                      >{{ $t(`ficha.ordenes.estados.${o.estado}`) }}</span
                    >
                  </li>
                </ul>
              </section>
            </template>
          </div>

          <!-- Resumen a un lado, sobre fondo gris -->
          <aside
            class="border-t lg:border-t-0 lg:border-l px-5 py-6"
            :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
          >
            <p class="fi-etiqueta">{{ $t("ficha.membresia") }}</p>
            <p class="mt-1 text-lg font-semibold tracking-tight">
              {{ $t(`recepcion.membresia.${resumen.membresia.estado}`) }}
            </p>
            <p
              v-if="resumen.membresia.pausada_hasta"
              class="text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                $t("pausaMembresia.enPausa", {
                  fecha: fecha(resumen.membresia.pausada_hasta),
                })
              }}
            </p>
            <p
              v-else-if="resumen.membresia.valido_hasta"
              class="text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                $t("ficha.derechos.vence", {
                  fecha: fecha(resumen.membresia.valido_hasta),
                })
              }}
            </p>

            <p
              v-if="resumen.alertas.length > 0"
              class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-sm"
            >
              <span
                v-for="a in resumen.alertas"
                :key="a"
                class="inline-flex items-center gap-1.5 font-medium"
                :style="{ color: colorAlerta(a) }"
              >
                <span
                  class="h-1.5 w-1.5 rounded-full"
                  :style="{ background: colorAlerta(a) }"
                  aria-hidden="true"
                />{{ $t(`recepcion.alertas.${a}`) }}</span
              >
            </p>

            <dl
              class="mt-5 rounded-xl border px-4 text-sm divide-y divide-[var(--borde)]"
              :style="{
                background: 'var(--superficie)',
                borderColor: 'var(--borde)',
              }"
            >
              <div class="fi-dato">
                <dt>{{ $t("ficha.saldo") }}</dt>
                <dd>
                  {{ $t("ficha.creditos", { n: resumen.saldo_creditos }) }}
                </dd>
              </div>
              <div class="fi-dato">
                <dt>{{ $t("ficha.proxima") }}</dt>
                <dd v-if="resumen.proxima_reserva">
                  {{ resumen.proxima_reserva.clase ?? "—" }}
                  <span
                    class="block text-xs font-normal"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{
                      fechaHora(
                        resumen.proxima_reserva.inicia_en,
                        resumen.proxima_reserva.zona_horaria,
                      )
                    }}</span
                  >
                </dd>
                <dd
                  v-else
                  class="font-normal"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("ficha.sinProxima") }}
                </dd>
              </div>
              <div class="fi-dato">
                <dt>{{ $t("ficha.alta") }}</dt>
                <dd>{{ fecha(ficha.persona.alta) }}</dd>
              </div>
              <div v-if="ficha.persona.sucursal" class="fi-dato">
                <dt>{{ $t("ficha.sucursal") }}</dt>
                <dd>{{ ficha.persona.sucursal }}</dd>
              </div>
              <div class="fi-dato">
                <dt>{{ $t("ficha.asistencias") }}</dt>
                <dd>
                  {{ resumen.asistencias }}
                  <span
                    v-if="resumen.primera_vez"
                    class="ml-1 text-xs"
                    :style="{ color: 'var(--aviso)' }"
                    >{{ $t("miembros.nuevo") }}</span
                  >
                </dd>
              </div>
              <div v-if="resumen.documentos_pendientes > 0" class="fi-dato">
                <dt>{{ $t("ficha.documentos") }}</dt>
                <dd :style="{ color: 'var(--aviso)' }">
                  {{ resumen.documentos_pendientes }}
                </dd>
              </div>
            </dl>
          </aside>
        </div>
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

<style scoped>
/* Etiqueta pequeña del resumen, como en la columna de detalle de la demo. */
.fi-etiqueta {
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--texto-suave);
}
.fi-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid;
}
.fi-fila:first-child {
  border-top: 0;
}
.fi-dato {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.6rem 0;
}
.fi-dato dt {
  color: var(--texto-suave);
}
.fi-dato dd {
  text-align: right;
  font-weight: 500;
}
</style>
