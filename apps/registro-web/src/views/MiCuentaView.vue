<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import AgendarCitaCuenta from "@/components/AgendarCitaCuenta.vue";
import ListaFormularios from "@/components/ListaFormularios.vue";
import { api, mensajeDeError } from "@/lib/api";
import type { FormularioPersona } from "@/lib/formularios";
import MisDocumentos from "@/components/MisDocumentos.vue";
import PaseEntrada from "@/components/PaseEntrada.vue";
import { useRetornoPago } from "@/lib/retornoPago";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Derecho {
  id: string;
  producto?: string | null;
  pausa_hasta?: string | null;
  ilimitado: boolean;
  saldo: number | null;
  disponible: number | null;
}
interface Reserva {
  id: string;
  sesion_id: string | null;
  estado: string;
  oferta: string | null;
  sucursal: string | null;
  inicia_en: string | null;
  zona_horaria: string | null;
  oferta_expira_en: string | null;
  orden_id: string | null;
}
interface Producto {
  id: string;
  nombre: string;
  tipo: string;
  precio_minor: number;
  moneda: string;
  ilimitado: boolean;
  creditos_incluidos: number | null;
}
interface Orden {
  id: string;
  estado: string;
  total_minor: number;
  moneda: string;
  fecha: string | null;
  lineas: {
    producto: string | null;
    cantidad: number;
    subtotal_minor: number;
  }[];
}
interface Clase {
  id: string;
  oferta: string | null;
  sucursal: string | null;
  inicia_en: string;
  zona_horaria: string;
  capacidad: number | null;
  ocupados: number;
}
interface Waiver {
  id: string;
  titulo: string;
  contenido: string;
  version: number;
}
interface Politica {
  horas_limite: number;
  penaliza_tarde: boolean;
  penaliza_no_show: boolean;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const derechos = ref<Derecho[]>([]);
const reservas = ref<Reserva[]>([]);
const clases = ref<Clase[]>([]);
const waivers = ref<Waiver[]>([]);
const productos = ref<Producto[]>([]);
const ordenes = ref<Orden[]>([]);
const politica = ref<Politica | null>(null);
const formularios = ref<FormularioPersona[]>([]);
const personaId = ref<string | null>(null);
// ¿El negocio cobra en línea? Entonces el alumno paga aquí lo pendiente.
const pagoEnLinea = ref(false);
const pagando = ref<string | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const mensaje = ref<string | null>(null);
const accionando = ref(false);
const comprando = ref<string | null>(null);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

function horaLocal(iso: string | null, zona: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona ?? "America/Mexico_City",
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(iso));
}
function creditos(u: number | null): number {
  return Math.round((u ?? 0) / 1000);
}
function llena(c: Clase): boolean {
  return c.capacidad !== null && c.ocupados >= c.capacidad;
}
function lugares(c: Clase): string {
  return c.capacidad !== null
    ? `${c.ocupados}/${c.capacidad}`
    : String(c.ocupados);
}

// `silencioso`: recarga sin ocultar la pantalla (p. ej. tras agendar una cita).
async function cargar(silencioso = false): Promise<void> {
  cargando.value = !silencioso;
  error.value = null;
  try {
    const [p, a, w, pr, o] = await Promise.all([
      api.get<{
        data: {
          derechos: Derecho[];
          reservas: Reserva[];
          politica_cancelacion: Politica | null;
          pago_en_linea?: boolean;
        };
      }>(`${base.value}/mi/perfil`),
      api.get<{ data: Clase[] }>(`${base.value}/mi/agenda`),
      api.get<{ data: Waiver[] }>(`${base.value}/mi/waivers`),
      api.get<{ data: Producto[] }>(`${base.value}/mi/productos`),
      api.get<{ data: Orden[] }>(`${base.value}/mi/ordenes`),
    ]);
    derechos.value = p.data.data.derechos;
    reservas.value = p.data.data.reservas;
    politica.value = p.data.data.politica_cancelacion;
    pagoEnLinea.value = p.data.data.pago_en_linea === true;
    clases.value = a.data.data;
    waivers.value = w.data.data;
    productos.value = pr.data.data;
    ordenes.value = o.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
  await cargarFormularios();
}

async function cargarFormularios(): Promise<void> {
  try {
    const { data } = await api.get<{
      data: { persona_id: string; formularios: FormularioPersona[] };
    }>(`${base.value}/mi/formularios`);
    personaId.value = data.data.persona_id;
    formularios.value = data.data.formularios;
  } catch {
    // Sin perfil de alumno: no hay formularios que llenar.
    formularios.value = [];
  }
}

// Al volver de la página de pago: aviso y, poco después, se recarga para ver los
// créditos (el webhook de la pasarela confirma el pago en segundos).
const retornoPago = useRetornoPago();
if (retornoPago.value === "exito") {
  window.setTimeout(() => void cargar(true), 4000);
}

// Paga en línea una orden propia (compra o cita apartada). Con pasarela de
// redirección se va al checkout; si no, el pago queda en proceso.
async function pagar(ordenId: string): Promise<void> {
  pagando.value = ordenId;
  error.value = null;
  mensaje.value = null;
  try {
    const { data } = await api.post<{
      data: { checkout?: { tipo?: string; url?: string } | null };
    }>(`${base.value}/mi/ordenes/${ordenId}/cobrar`, { metodo: "tarjeta" });
    const checkout = data.data.checkout ?? {};
    if (
      checkout.tipo === "redirect" &&
      typeof checkout.url === "string" &&
      checkout.url !== ""
    ) {
      window.location.href = checkout.url;
      return;
    }
    mensaje.value = "pago";
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    pagando.value = null;
  }
}

async function reservar(clase: Clase, esperar: boolean): Promise<void> {
  accionando.value = true;
  error.value = null;
  mensaje.value = null;
  try {
    await api.post(`${base.value}/mi/reservas`, {
      sesion_id: clase.id,
      esperar,
    });
    mensaje.value = esperar ? "espera" : "ok";
    await cargar(true);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function cancelar(r: Reserva): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/mi/reservas/${r.id}/cancelar`, {});
    await cargar(true);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function aceptar(r: Reserva): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/mi/reservas/${r.id}/aceptar`, {});
    await cargar(true);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function aceptarWaiver(w: Waiver): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/mi/waivers/${w.id}/aceptar`, {});
    await cargar(true);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

// Compra un producto para sí mismo: crea la orden pendiente. El pago se completa en
// línea (pasarela) o en el estudio; al confirmarse, los créditos aparecen aquí.
async function comprar(p: Producto): Promise<void> {
  comprando.value = p.id;
  error.value = null;
  mensaje.value = null;
  try {
    await api.post(`${base.value}/mi/ordenes`, {
      items: [{ producto_id: p.id, cantidad: 1 }],
    });
    mensaje.value = "comprado";
    await cargar(true);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    comprando.value = null;
  }
}

// Match por sesion_id (no por oferta+hora): dos clases iguales en distinta sucursal
// ya no se confunden (P0 #4).
const reservadas = computed(
  () =>
    new Set(
      reservas.value
        .map((r) => r.sesion_id)
        .filter((id): id is string => id !== null),
    ),
);

onMounted(() => cargar());
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 py-10">
    <h1 class="text-xl font-semibold">{{ $t("miCuenta.titulo") }}</h1>
    <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("miCuenta.subtitulo") }}
    </p>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-if="retornoPago"
      class="mt-4 text-sm"
      role="status"
      :style="{
        color: retornoPago === 'exito' ? 'var(--exito)' : 'var(--aviso)',
      }"
    >
      {{ $t(`pagoEnLinea.${retornoPago}`) }}
    </p>
    <p
      v-if="mensaje === 'pago'"
      class="mt-4 text-sm"
      role="status"
      :style="{ color: 'var(--exito)' }"
    >
      {{ $t("miCuentaExtra.pagoEnProceso") }}
    </p>

    <template v-if="!cargando">
      <!-- Consentimientos pendientes -->
      <div
        v-if="waivers.length > 0"
        class="mt-6 tu-card p-6"
        :style="{ borderLeft: '4px solid var(--primario)' }"
      >
        <h2 class="font-light text-lg">{{ $t("miCuenta.waiversTitulo") }}</h2>
        <ul class="mt-3 space-y-3">
          <li v-for="w in waivers" :key="w.id" class="text-sm">
            <div class="font-semibold">{{ w.titulo }}</div>
            <p
              class="mt-1 whitespace-pre-line"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ w.contenido }}
            </p>
            <button
              class="tu-btn tu-btn-primario mt-2"
              :disabled="accionando"
              @click="aceptarWaiver(w)"
            >
              {{ $t("miCuenta.aceptarWaiver") }}
            </button>
          </li>
        </ul>
      </div>

      <div class="mt-6 grid gap-6 md:grid-cols-2">
        <!-- Creditos -->
        <div class="tu-card p-6">
          <h2 class="font-light text-lg">{{ $t("miCuenta.creditos") }}</h2>
          <ul v-if="derechos.length > 0" class="mt-3 space-y-2 text-sm">
            <li
              v-for="d in derechos"
              :key="d.id"
              class="flex flex-wrap items-center justify-between gap-x-3"
            >
              <span
                v-if="d.pausa_hasta"
                class="w-full text-xs"
                style="color: var(--aviso)"
                >{{ d.producto ? `${d.producto} · ` : ""
                }}{{
                  $t("pausaMembresia.enPausa", {
                    fecha: new Intl.DateTimeFormat("es-MX", {
                      day: "numeric",
                      month: "long",
                    }).format(new Date(`${d.pausa_hasta}T12:00:00`)),
                  })
                }}</span
              >
              <span v-if="d.ilimitado" class="tu-badge tu-badge-exito">{{
                $t("miCuenta.ilimitado")
              }}</span>
              <template v-else>
                <span :style="{ color: 'var(--texto-suave)' }">{{
                  $t("miCuenta.disponible")
                }}</span>
                <span class="font-bold text-lg">{{
                  creditos(d.disponible)
                }}</span>
              </template>
            </li>
          </ul>
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("miCuenta.sinCreditos") }}
          </p>
        </div>

        <!-- Pase de entrada (QR) -->
        <PaseEntrada v-if="personaId !== null" />

        <!-- Documentos que pide el negocio -->
        <MisDocumentos v-if="personaId !== null" />

        <!-- Mis reservas -->
        <div class="tu-card p-6">
          <h2 class="font-light text-lg">{{ $t("miCuenta.misReservas") }}</h2>
          <ul v-if="reservas.length > 0" class="mt-3 space-y-2 text-sm">
            <li
              v-for="r in reservas"
              :key="r.id"
              class="flex items-center justify-between gap-2"
            >
              <span class="min-w-0">
                <span class="font-medium">{{ r.oferta ?? "—" }}</span>
                <span :style="{ color: 'var(--texto-suave)' }">
                  · {{ horaLocal(r.inicia_en, r.zona_horaria) }}</span
                >
                <span
                  v-if="r.sucursal"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  · {{ r.sucursal }}</span
                >
                <span
                  class="tu-badge ml-1"
                  :class="{
                    'tu-badge-exito': r.estado === 'confirmada',
                    'tu-badge-aviso':
                      r.estado === 'ofrecida' ||
                      r.estado === 'en_espera' ||
                      r.estado === 'pendiente_pago',
                  }"
                  >{{ $t(`miCuenta.${r.estado}`) }}</span
                >
              </span>
              <span class="flex items-center gap-2 shrink-0">
                <button
                  v-if="r.estado === 'ofrecida'"
                  class="tu-btn tu-btn-primario"
                  :disabled="accionando"
                  @click="aceptar(r)"
                >
                  {{ $t("miCuenta.aceptarPlaza") }}
                </button>
                <button
                  v-if="
                    r.estado === 'pendiente_pago' &&
                    r.orden_id !== null &&
                    pagoEnLinea
                  "
                  class="tu-btn tu-btn-primario"
                  :disabled="pagando !== null"
                  @click="pagar(r.orden_id)"
                >
                  {{
                    pagando === r.orden_id
                      ? $t("miCuentaExtra.pagando")
                      : $t("miCuentaExtra.pagar")
                  }}
                </button>
                <button
                  class="tu-enlace"
                  style="color: var(--error)"
                  :disabled="accionando"
                  @click="cancelar(r)"
                >
                  {{ $t("miCuenta.cancelar") }}
                </button>
              </span>
            </li>
          </ul>
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("miCuenta.sinReservas") }}
          </p>
          <!-- Reglas de cancelacion -->
          <p
            v-if="politica"
            class="mt-3 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("miCuenta.politica", { horas: politica.horas_limite }) }}
          </p>
        </div>
      </div>

      <!-- Comprar (autoservicio comercial) -->
      <div v-if="productos.length > 0" class="mt-6 tu-card p-6">
        <h2 class="font-light text-lg">{{ $t("miCuenta.comprar.titulo") }}</h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("miCuenta.comprar.subtitulo") }}
        </p>
        <p
          v-if="mensaje === 'comprado'"
          class="mt-2 text-sm"
          :style="{ color: 'var(--exito)' }"
        >
          {{ $t("miCuenta.comprar.creada") }}
        </p>
        <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <li
            v-for="p in productos"
            :key="p.id"
            class="rounded-2xl border p-4 flex flex-col"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <span class="tu-badge self-start">{{
              $t(`miCuenta.comprar.tipos.${p.tipo}`)
            }}</span>
            <p class="mt-2 font-semibold">{{ p.nombre }}</p>
            <p class="mt-1 text-xl font-semibold">
              {{ dinero(p.precio_minor, p.moneda) }}
            </p>
            <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
              <template v-if="p.ilimitado">{{
                $t("miCuenta.ilimitado")
              }}</template>
              <template v-else-if="p.creditos_incluidos">{{
                $t("miCuenta.comprar.creditos", {
                  n: p.creditos_incluidos / 1000,
                })
              }}</template>
            </p>
            <button
              class="tu-btn tu-btn-primario mt-3 w-full"
              :disabled="comprando !== null"
              @click="comprar(p)"
            >
              {{
                comprando === p.id
                  ? $t("miCuenta.comprar.comprando")
                  : $t("miCuenta.comprar.comprar")
              }}
            </button>
          </li>
        </ul>
      </div>

      <!-- Agendar una cita (negocios de citas) -->
      <div v-if="sesion.esCitas" class="mt-6 tu-card p-6">
        <h2 class="font-light text-lg">{{ $t("citaCuenta.titulo") }}</h2>
        <AgendarCitaCuenta class="mt-3" @agendada="cargar(true)" />
      </div>

      <!-- Proximas clases -->
      <div v-else class="mt-6 tu-card p-6">
        <h2 class="font-light text-lg">{{ $t("miCuenta.agenda") }}</h2>
        <p
          v-if="mensaje === 'ok'"
          class="mt-2 text-sm"
          :style="{ color: 'var(--exito)' }"
        >
          {{ $t("miCuenta.reservado") }}
        </p>
        <p
          v-else-if="mensaje === 'espera'"
          class="mt-2 text-sm"
          :style="{ color: 'var(--exito)' }"
        >
          {{ $t("miCuenta.enListaEspera") }}
        </p>
        <ul v-if="clases.length > 0" class="mt-3 space-y-2">
          <li
            v-for="c in clases"
            :key="c.id"
            class="flex items-center justify-between gap-2 text-sm"
          >
            <span class="min-w-0">
              <span class="font-medium">{{ c.oferta ?? "—" }}</span>
              <span :style="{ color: 'var(--texto-suave)' }">
                · {{ horaLocal(c.inicia_en, c.zona_horaria) }}</span
              >
              <span v-if="c.sucursal" :style="{ color: 'var(--texto-suave)' }">
                · {{ c.sucursal }}</span
              >
              <span
                class="tu-badge ml-1"
                :class="llena(c) ? 'tu-badge-aviso' : 'tu-badge-exito'"
                >{{ lugares(c) }}</span
              >
            </span>
            <span class="flex items-center gap-2 shrink-0">
              <template v-if="!reservadas.has(c.id)">
                <button
                  v-if="!llena(c)"
                  class="tu-btn tu-btn-primario"
                  :disabled="accionando"
                  @click="reservar(c, false)"
                >
                  {{ $t("miCuenta.reservar") }}
                </button>
                <button
                  v-else
                  class="tu-btn tu-btn-fantasma"
                  :disabled="accionando"
                  @click="reservar(c, true)"
                >
                  {{ $t("miCuenta.listaEspera") }}
                </button>
              </template>
              <span v-else class="tu-badge tu-badge-exito">{{
                $t("miCuenta.reservada")
              }}</span>
            </span>
          </li>
        </ul>
        <p v-else class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("miCuenta.sinClases") }}
        </p>
      </div>

      <!-- Mis formularios -->
      <div
        v-if="formularios.length > 0 && personaId !== null"
        class="mt-6 tu-card p-6"
      >
        <h2 class="font-light text-lg">
          {{ $t("miCuentaExtra.formularios") }}
        </h2>
        <ListaFormularios
          class="mt-2"
          :formularios="formularios"
          :persona-id="personaId"
          :puede-responder="true"
          @guardado="cargarFormularios"
        />
      </div>

      <!-- Mis compras (historial) -->
      <div v-if="ordenes.length > 0" class="mt-6 tu-card p-6">
        <h2 class="font-light text-lg">{{ $t("miCuenta.compras.titulo") }}</h2>
        <ul class="mt-3 space-y-2 text-sm">
          <li
            v-for="o in ordenes"
            :key="o.id"
            class="flex items-center justify-between gap-2"
          >
            <span class="min-w-0">
              <span class="font-medium">{{
                o.lineas
                  .map((l) => l.producto)
                  .filter(Boolean)
                  .join(", ") || "—"
              }}</span>
              <span v-if="o.fecha" :style="{ color: 'var(--texto-suave)' }">
                · {{ horaLocal(o.fecha, null) }}</span
              >
            </span>
            <span class="flex items-center gap-2 shrink-0">
              <span class="font-semibold">{{
                dinero(o.total_minor, o.moneda)
              }}</span>
              <span
                class="tu-badge"
                :class="
                  o.estado === 'pagada' ? 'tu-badge-exito' : 'tu-badge-aviso'
                "
                >{{ $t(`miCuenta.compras.estados.${o.estado}`) }}</span
              >
              <button
                v-if="o.estado === 'pendiente' && pagoEnLinea"
                class="tu-btn tu-btn-primario text-sm"
                :disabled="pagando !== null"
                @click="pagar(o.id)"
              >
                {{
                  pagando === o.id
                    ? $t("miCuentaExtra.pagando")
                    : $t("miCuentaExtra.pagar")
                }}
              </button>
            </span>
          </li>
        </ul>
        <p class="mt-3 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("miCuenta.compras.nota") }}
        </p>
      </div>
    </template>
  </section>
</template>
