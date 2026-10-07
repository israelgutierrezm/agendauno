<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute, useRouter } from "vue-router";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import CortePlanes from "@/components/CortePlanes.vue";
import ExpedientePersona from "@/components/ExpedientePersona.vue";
import PanelEditarMiembro, {
  type MiembroEditable,
} from "@/components/PanelEditarMiembro.vue";
import PanelMiembro from "@/components/PanelMiembro.vue";
import RegistrarPagoOrden, {
  type OrdenPorCobrar,
} from "@/components/RegistrarPagoOrden.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { dinero as dineroDelPais } from "@/lib/formato";
import { edadDe, fechaNacimientoTexto } from "@/lib/datosPersonales";
import { useRegreso } from "@/lib/regreso";
import { plural } from "@/lib/terminologia";
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
  // La más reciente a la que llegó (en citas, qué y con quién).
  ultima_visita?: {
    clase: string | null;
    profesional: string | null;
    inicia_en: string | null;
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
  // Llegó tarde (cuenta como asistencia, ADR 0101).
  retardo?: boolean;
  cancelada_por?: "cliente" | "negocio" | "sistema" | null;
  tipo?: "clase" | "cita" | null;
  instructor?: string | null;
}
interface Orden {
  id: string;
  fecha: string | null;
  estado: string;
  total_minor: number;
  moneda: string;
  metodo_pago: string | null;
  pagada_en: string | null;
  // Qué se pagó y, si es una cita, con quién y cuándo.
  concepto?: string | null;
  sesion?: {
    profesional: string | null;
    inicia_en: string;
    zona_horaria: string | null;
  } | null;
}
interface Ficha {
  persona: {
    id: string;
    nombre_completo: string;
    nombre?: string;
    segundo_nombre?: string | null;
    primer_apellido?: string | null;
    segundo_apellido?: string | null;
    email: string | null;
    celular?: string | null;
    fecha_nacimiento?: string | null;
    genero?: string | null;
    tipo: string;
    activo: boolean;
    es_facturable: boolean;
    archivado: boolean;
    alta: string | null;
    sucursal: string | null;
  };
  derechos: Derecho[];
  reservas: Reserva[];
  // Sin permiso para ver órdenes, el servidor no las manda (null).
  ordenes: Orden[] | null;
  pendientes?: Orden[] | null;
}

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
// Actividad (membresías, reservas, compras), Planes (el corte de cada paquete o
// membresía: qué incluía y cómo lo usó) o Expediente (documentos, formularios,
// consentimientos). Va en la URL (?seccion=planes) para poder enlazarla.
type Seccion = "actividad" | "planes" | "expediente";
const seccion = computed<Seccion>(() =>
  route.query.seccion === "expediente" ||
  (route.query.seccion === "planes" && puedeVerDerechos.value)
    ? route.query.seccion
    : "actividad",
);
function irSeccion(s: Seccion): void {
  void router.replace({
    query: { ...route.query, seccion: s === "actividad" ? undefined : s },
  });
}
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const personaId = computed(() => String(route.params.id));
const puedeGestionar = computed(() => sesion.puede("miembros.gestionar"));
// El corte de cada plan y los movimientos de créditos piden ver derechos.
const puedeVerDerechos = computed(() => sesion.puede("derechos.ver"));
const puedeVender = computed(() => sesion.puede("ordenes.gestionar"));
const puedeRecargar = computed(() => sesion.puede("membresias.gestionar"));

// Negocio de citas: el cliente paga cada servicio; sin bono ni membresía no le
// falta nada. Lo que importa es su próxima cita, su última visita, lo que suele
// pedir y con quién, y lo que debe. Los planes aparecen solo si tiene alguno.
const esCitas = computed(() => sesion.esCitas === true);
const conPlanes = computed(() => (ficha.value?.derechos.length ?? 0) > 0);
const mostrarPlanes = computed(() => !esCitas.value || conPlanes.value);
const pendientes = computed(() => ficha.value?.pendientes ?? []);
function masFrecuente(valores: (string | null | undefined)[]): string | null {
  const cuenta = new Map<string, number>();
  for (const v of valores) {
    if (v) {
      cuenta.set(v, (cuenta.get(v) ?? 0) + 1);
    }
  }
  return [...cuenta].sort((a, b) => b[1] - a[1])[0]?.[0] ?? null;
}
// Lo que más pide y con quién, de las visitas a las que llegó.
const habituales = computed(() => {
  const visitas = (ficha.value?.reservas ?? []).filter(
    (r) => r.asistencia === "presente",
  );
  return {
    servicio: masFrecuente(visitas.map((r) => r.clase)),
    profesional: masFrecuente(visitas.map((r) => r.instructor)),
  };
});

// Cobrar lo que debe desde la ficha (el mismo recorrido que «Por cobrar»).
const cobrando = ref<OrdenPorCobrar | null>(null);
const avisoCobro = ref<string | null>(null);
function abrirCobro(o: Orden): void {
  avisoCobro.value = null;
  cobrando.value = {
    id: o.id,
    persona: ficha.value?.persona.nombre_completo ?? null,
    concepto: o.concepto ?? null,
    total_minor: o.total_minor,
    moneda: o.moneda,
  };
}
async function alRegistrar(aviso: string): Promise<void> {
  cobrando.value = null;
  await cargar();
  avisoCobro.value = aviso;
}
// Si es una cita: con quién y cuándo; si no, la fecha de la compra.
function detalleOrden(o: Orden): string {
  if (o.sesion) {
    return [
      o.sesion.profesional
        ? t("cobranza.pendientes.con", { nombre: o.sesion.profesional })
        : null,
      fechaHora(o.sesion.inicia_en, o.sesion.zona_horaria),
    ]
      .filter(Boolean)
      .join(" · ");
  }
  return t("cobranza.pendientes.comprada", { fecha: fecha(o.fecha) });
}
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
  if (
    !(await confirmar(
      t("confirmaciones.recargar", { n: recarga.value.creditos }),
      { aceptar: t("confirmaciones.agregar") },
    ))
  ) {
    return;
  }
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
  if (
    !(await confirmar(
      t("confirmaciones.pausar", { fecha: fecha(pausa.value.hasta) }),
      { aceptar: t("confirmaciones.pausarAceptar") },
    ))
  ) {
    return;
  }
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

// Membresías y paquetes: lo vigente (no cancelado ni vencido) va primero con su
// saldo; lo demás es historial y se abre aparte. El saldo no suma lo vencido.
function hoyLocal(): string {
  const d = new Date();
  const dos = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`;
}
function esVigente(d: Derecho): boolean {
  return (
    d.estado !== "cancelado" &&
    (d.valido_hasta === null || d.valido_hasta.slice(0, 10) >= hoyLocal())
  );
}
const derechosVigentes = computed(() =>
  (ficha.value?.derechos ?? []).filter(esVigente),
);
const derechosHistorial = computed(() =>
  (ficha.value?.derechos ?? []).filter((d) => !esVigente(d)),
);
const verHistorial = ref(false);
const derechosVisibles = computed(() => [
  ...derechosVigentes.value,
  ...(verHistorial.value ? derechosHistorial.value : []),
]);
// El saldo, con un solo criterio en toda la ficha (contenido y resumen): con una
// membresía ilimitada vigente, «Ilimitado»; si no, lo que puede usar
// («disponibles») y lo que ya tiene reservado («apartadas»), de lo vigente.
const saldoVigente = computed(() => {
  const limitados = derechosVigentes.value.filter((d) => !d.ilimitado);
  const disponibles = limitados.reduce(
    (t, d) => t + Math.max(0, d.disponible_unidades ?? 0),
    0,
  );
  const saldo = limitados.reduce(
    (t, d) => t + Math.max(0, d.saldo_unidades ?? 0),
    0,
  );
  return {
    ilimitado: derechosVigentes.value.some((d) => d.ilimitado),
    disponibles: disponibles / 1000,
    apartadas: Math.max(0, saldo - disponibles) / 1000,
  };
});
/** «9 disponibles · 3 apartadas» (sin apartadas, solo lo disponible). */
function textoSaldo(disponibles: number, apartadas: number): string {
  const d = t(
    "ficha.derechos.disponible",
    { n: disponibles },
    disponibles === 1 ? 1 : 2,
  );
  return apartadas > 0
    ? `${d} · ${t("ficha.derechos.apartadas", { n: apartadas }, apartadas === 1 ? 1 : 2)}`
    : d;
}
const textoSaldoVigente = computed(() =>
  derechosVigentes.value.length === 0
    ? t("ficha.derechos.sinSaldo")
    : saldoVigente.value.ilimitado
      ? t("ficha.derechos.ilimitado")
      : textoSaldo(
          saldoVigente.value.disponibles,
          saldoVigente.value.apartadas,
        ),
);
const cargando = ref(true);
const error = ref<string | null>(null);
const editando = ref<MiembroEditable | null>(null);
const vendiendo = ref(false);

// Con los números del país del negocio («$ 1.250,50» en Colombia).
function dinero(minor: number, moneda: string): string {
  return dineroDelPais(minor, moneda, sesion.pais);
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
  // Una fecha de calendario (AAAA-MM-DD) es ese día, no la medianoche UTC (que en
  // México cae el día anterior): se arma en la fecha local.
  const soloFecha = /^\d{4}-\d{2}-\d{2}$/.test(iso);
  const [a, m, d] = iso.slice(0, 10).split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(soloFecha ? new Date(a, m - 1, d) : new Date(iso));
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
    // Con las partes del nombre (si no, se guardaría el nombre completo como nombre).
    nombre: p.nombre ?? p.nombre_completo,
    segundo_nombre: p.segundo_nombre ?? null,
    primer_apellido: p.primer_apellido ?? null,
    segundo_apellido: p.segundo_apellido ?? null,
    email: p.email,
    celular: p.celular ?? null,
    activo: p.activo,
    es_facturable: p.es_facturable,
    archivado: p.archivado,
    fecha_nacimiento: p.fecha_nacimiento ?? null,
    genero: p.genero ?? null,
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

// «Volver»: a la pantalla de donde se llegó (Recepción, respuestas…) o al directorio.
const regreso = useRegreso({
  name: "miembros",
  etiqueta: () =>
    t("ficha.volver", {
      grupo: plural(sesion.terminologia.miembro).toLowerCase(),
    }),
});
</script>

<template>
  <section class="tu-pagina">
    <RouterLink :to="regreso.destino.value" class="tu-enlace text-sm"
      >← {{ regreso.etiqueta.value }}</RouterLink
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

        <!-- En el teléfono: lo que debe y sus planes, luego el resumen y al final
             los historiales (la columna se «abre» para ordenar sus secciones). -->
        <div class="flex flex-col lg:grid lg:grid-cols-[minmax(0,1fr)_20rem]">
          <div class="min-w-0 max-lg:contents">
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
                  v-if="puedeVerDerechos && mostrarPlanes"
                  type="button"
                  :aria-pressed="seccion === 'planes'"
                  @click="irSeccion('planes')"
                >
                  {{ $t("planes.corte.tituloEquipo") }}
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

            <div v-if="seccion === 'planes'" class="px-5 py-5">
              <CortePlanes
                equipo
                :url="`${base}/miembros/${ficha.persona.id}/planes`"
              />
            </div>
            <ExpedientePersona
              v-else-if="seccion === 'expediente'"
              :persona-id="ficha.persona.id"
              tipo-persona="miembro"
            />
            <template v-else>
              <p
                v-if="avisoCobro"
                class="px-5 pt-5 text-sm"
                role="status"
                :style="{ color: 'var(--exito)' }"
              >
                {{ avisoCobro }}
              </p>
              <!-- Lo que debe: primero, con «Registrar pago» -->
              <section
                v-if="pendientes.length > 0"
                class="px-5 py-5"
                data-prueba="pendientes-ficha"
              >
                <h2 class="text-sm font-semibold">
                  {{ $t("ficha.pendientes.titulo") }}
                </h2>
                <ul class="mt-1">
                  <li
                    v-for="o in pendientes"
                    :key="o.id"
                    class="fi-fila"
                    :style="{ borderColor: 'var(--borde)' }"
                  >
                    <div class="min-w-0">
                      <p class="font-medium truncate">
                        {{ o.concepto ?? "—" }}
                      </p>
                      <p
                        class="mt-0.5 text-xs first-letter:uppercase"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        {{ detalleOrden(o) }}
                      </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                      <span class="font-semibold tabular-nums">{{
                        dinero(o.total_minor, o.moneda)
                      }}</span>
                      <button
                        v-if="puedeVender"
                        type="button"
                        class="tu-btn tu-btn-primario text-sm"
                        data-prueba="cobrar-pendiente"
                        @click="abrirCobro(o)"
                      >
                        {{ $t("cobranza.pendientes.registrar") }}
                      </button>
                    </div>
                  </li>
                </ul>
              </section>

              <!-- Citas: sus visitas (próxima, última, lo habitual) -->
              <section
                v-if="esCitas"
                class="px-5 py-5"
                :class="{ 'border-t': pendientes.length > 0 }"
                :style="{ borderColor: 'var(--borde)' }"
                data-prueba="visitas-cliente"
              >
                <h2 class="text-sm font-semibold">
                  {{ $t("ficha.visitas.titulo") }}
                </h2>
                <dl class="fi-visitas">
                  <div>
                    <dt>{{ $t("ficha.visitas.proxima") }}</dt>
                    <dd v-if="resumen.proxima_reserva">
                      {{ resumen.proxima_reserva.clase ?? "—" }}
                      <span class="fi-visitas-detalle first-letter:uppercase">{{
                        fechaHora(
                          resumen.proxima_reserva.inicia_en,
                          resumen.proxima_reserva.zona_horaria,
                        )
                      }}</span>
                    </dd>
                    <dd v-else class="fi-visitas-vacio">
                      {{ $t("ficha.visitas.sinProxima") }}
                    </dd>
                  </div>
                  <div>
                    <dt>{{ $t("ficha.visitas.ultima") }}</dt>
                    <dd v-if="resumen.ultima_visita">
                      {{ resumen.ultima_visita.clase ?? "—" }}
                      <span class="fi-visitas-detalle first-letter:uppercase">{{
                        [
                          fechaHora(
                            resumen.ultima_visita.inicia_en,
                            resumen.ultima_visita.zona_horaria,
                          ),
                          resumen.ultima_visita.profesional,
                        ]
                          .filter(Boolean)
                          .join(" · ")
                      }}</span>
                    </dd>
                    <dd v-else class="fi-visitas-vacio">
                      {{ $t("ficha.visitas.sinUltima") }}
                    </dd>
                  </div>
                  <div>
                    <dt>{{ $t("ficha.visitas.servicio") }}</dt>
                    <dd>{{ habituales.servicio ?? "—" }}</dd>
                  </div>
                  <div>
                    <dt>{{ $t("ficha.visitas.profesional") }}</dt>
                    <dd>{{ habituales.profesional ?? "—" }}</dd>
                  </div>
                </dl>
              </section>

              <!-- Membresías y paquetes (derechos); en citas, solo si tiene -->
              <section
                v-if="mostrarPlanes"
                class="px-5 py-5"
                :class="{ 'border-t': pendientes.length > 0 || esCitas }"
                :style="{ borderColor: 'var(--borde)' }"
              >
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
                <template v-else>
                  <p class="mt-1 text-sm" data-prueba="saldo-vigente">
                    {{
                      derechosVigentes.length === 0
                        ? $t("ficha.derechos.sinVigente")
                        : $t("ficha.derechos.saldoVigente", {
                            saldo: textoSaldoVigente,
                          })
                    }}
                  </p>
                  <ul class="mt-1">
                    <template v-for="(d, i) in derechosVisibles" :key="d.id">
                      <li
                        v-if="i === derechosVigentes.length"
                        class="fi-subtitulo"
                        data-prueba="historial-planes"
                      >
                        {{ $t("ficha.derechos.historial") }}
                      </li>
                      <li
                        class="fi-fila"
                        :class="{ 'fi-pasado': i >= derechosVigentes.length }"
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
                            <!-- Lo que puede usar y, aparte, lo que ya reservó. -->
                            <p class="font-semibold tabular-nums">
                              {{
                                $t(
                                  "ficha.derechos.disponible",
                                  { n: (d.disponible_unidades ?? 0) / 1000 },
                                  d.disponible_unidades === 1000 ? 1 : 2,
                                )
                              }}
                            </p>
                            <p
                              v-if="
                                (d.saldo_unidades ?? 0) >
                                (d.disponible_unidades ?? 0)
                              "
                              class="text-xs"
                              :style="{ color: 'var(--texto-suave)' }"
                            >
                              {{
                                $t(
                                  "ficha.derechos.apartadas",
                                  {
                                    n:
                                      ((d.saldo_unidades ?? 0) -
                                        (d.disponible_unidades ?? 0)) /
                                      1000,
                                  },
                                  (d.saldo_unidades ?? 0) -
                                    (d.disponible_unidades ?? 0) ===
                                    1000
                                    ? 1
                                    : 2,
                                )
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
                              v-if="puedeVerDerechos"
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
                            :disabled="
                              guardandoRecarga || recarga.creditos <= 0
                            "
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
                                m.concepto ??
                                $t(`creditosFicha.tipos.${m.tipo}`)
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
                    </template>
                  </ul>
                  <button
                    v-if="derechosHistorial.length > 0"
                    type="button"
                    class="tu-enlace mt-2 text-sm"
                    :aria-expanded="verHistorial"
                    data-prueba="ver-historial-planes"
                    @click="verHistorial = !verHistorial"
                  >
                    {{
                      verHistorial
                        ? $t("ficha.derechos.ocultarHistorial")
                        : $t("ficha.derechos.verHistorial", {
                            n: derechosHistorial.length,
                          })
                    }}
                  </button>
                </template>
              </section>

              <!-- Historial de reservas -->
              <section
                class="px-5 py-5 border-t max-lg:order-2"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <h2 class="text-sm font-semibold">
                  {{
                    esCitas
                      ? $t("ficha.reservas.tituloCitas")
                      : $t("ficha.reservas.titulo")
                  }}
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
                      >{{
                        $t(
                          r.retardo
                            ? "agenda.roster.retardo"
                            : "agenda.roster.presente",
                        )
                      }}</span
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

              <!-- Historial de compras (solo a quien puede ver órdenes) -->
              <section
                v-if="ficha.ordenes !== null"
                class="px-5 py-5 border-t max-lg:order-2"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <h2 class="text-sm font-semibold">
                  {{ $t("ficha.ordenes.titulo") }}
                </h2>
                <p
                  v-if="(ficha.ordenes ?? []).length === 0"
                  class="mt-2 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("ficha.ordenes.vacio") }}
                </p>
                <ul v-else class="mt-1">
                  <li
                    v-for="o in ficha.ordenes ?? []"
                    :key="o.id"
                    class="fi-fila"
                    :style="{ borderColor: 'var(--borde)' }"
                  >
                    <div class="min-w-0">
                      <p class="font-medium truncate">
                        {{ o.concepto ?? "—" }}
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
                    <div class="flex shrink-0 items-center gap-3">
                      <span class="font-medium tabular-nums">{{
                        dinero(o.total_minor, o.moneda)
                      }}</span>
                      <span
                        class="tu-badge"
                        :class="
                          o.estado === 'pagada'
                            ? 'tu-badge-exito'
                            : 'tu-badge-aviso'
                        "
                        >{{ $t(`ficha.ordenes.estados.${o.estado}`) }}</span
                      >
                    </div>
                  </li>
                </ul>
              </section>
            </template>
          </div>

          <!-- Resumen a un lado, sobre fondo gris -->
          <aside
            class="border-t lg:border-t-0 lg:border-l px-5 py-6 max-lg:order-1"
            :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
          >
            <template v-if="!mostrarPlanes">
              <p class="fi-etiqueta">{{ $t("ficha.visitas.ultima") }}</p>
              <p class="mt-1 text-lg font-semibold tracking-tight">
                {{
                  resumen.ultima_visita
                    ? (resumen.ultima_visita.clase ?? "—")
                    : $t("ficha.visitas.sinUltima")
                }}
              </p>
              <p
                v-if="resumen.ultima_visita"
                class="text-sm first-letter:uppercase"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{
                  fechaHora(
                    resumen.ultima_visita.inicia_en,
                    resumen.ultima_visita.zona_horaria,
                  )
                }}
              </p>
            </template>
            <template v-else>
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
            </template>

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
              <div v-if="mostrarPlanes" class="fi-dato">
                <dt>{{ $t("ficha.saldo") }}</dt>
                <dd data-prueba="saldo-resumen">{{ textoSaldoVigente }}</dd>
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
              <div
                v-if="ficha.persona.fecha_nacimiento"
                class="fi-dato"
                data-prueba="ficha-nacimiento"
              >
                <dt>{{ $t("datosPersonales.fechaNacimiento") }}</dt>
                <dd>
                  {{ fechaNacimientoTexto(ficha.persona.fecha_nacimiento) }}
                  <span :style="{ color: 'var(--texto-suave)' }"
                    >·
                    {{
                      $t("datosPersonales.edad", {
                        n: edadDe(ficha.persona.fecha_nacimiento),
                      })
                    }}</span
                  >
                </dd>
              </div>
              <div v-if="ficha.persona.genero" class="fi-dato">
                <dt>{{ $t("datosPersonales.genero") }}</dt>
                <dd>
                  {{ $t(`datosPersonales.generos.${ficha.persona.genero}`) }}
                </dd>
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
    <RegistrarPagoOrden
      :base="base"
      :orden="cobrando"
      @cerrar="cobrando = null"
      @registrado="alRegistrar"
    />
    <PanelMiembro
      v-if="vendiendo && ficha"
      :persona-id="personaId"
      :nombre="ficha.persona.nombre_completo"
      venta
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
/* Visitas del cliente (citas): cuatro datos en dos columnas. */
.fi-visitas {
  display: grid;
  gap: 0.9rem 1.5rem;
  margin-top: 0.75rem;
  font-size: 0.875rem;
}
@media (min-width: 640px) {
  .fi-visitas {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
.fi-visitas dt {
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.fi-visitas dd {
  margin-top: 0.15rem;
  font-weight: 500;
}
.fi-visitas-detalle {
  display: block;
  font-size: 0.75rem;
  font-weight: 400;
  color: var(--texto-suave);
}
.fi-visitas-vacio {
  font-weight: 400 !important;
  color: var(--texto-suave);
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
/* Planes que ya no están vigentes (historial): atenuados, con su subtítulo. */
.fi-subtitulo {
  padding: 0.9rem 0 0.25rem;
  font-size: 0.75rem;
  font-weight: 500;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--texto-suave);
}
.fi-pasado {
  opacity: 0.72;
}
</style>
