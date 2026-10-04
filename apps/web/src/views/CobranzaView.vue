<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import CorregirCobro from "@/components/CorregirCobro.vue";
import CorteDeCaja from "@/components/CorteDeCaja.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PorConciliar from "@/components/PorConciliar.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

// Lo que ya se debe (GET /cobranza/pendientes): compras sin pagar y citas o clases
// de pago que ya pasaron. Es el mismo criterio que el Inicio.
interface Pendiente {
  id: string;
  persona: { id: string; nombre: string } | null;
  concepto: string | null;
  total_minor: number;
  moneda: string;
  creada_en: string | null;
  sesion: {
    tipo: "clase" | "cita";
    profesional: string | null;
    inicia_en: string;
    zona_horaria: string | null;
    sucursal: string | null;
  } | null;
}
interface MetaPendientes {
  page: number;
  ultima_pagina: number;
  total: number;
  per_page: number;
  por_cobrar: { moneda: string; total_minor: number }[];
  proximas: number;
}
interface Moroso {
  id: string;
  acuerdo: string | null;
  persona: { id: string; nombre: string } | null;
  estado: string;
  intentos: number;
  gracia_hasta: string;
  ultimo_motivo: string | null;
}
interface Pago {
  id: string;
  fecha: string | null;
  persona: string | null;
  monto_minor: number;
  moneda: string;
  estado: string;
  proveedor: string | null;
  metodo: string | null;
  reembolsado_minor: number;
  reembolsable_minor: number;
  // Tiene devoluciones (aunque estén en curso o hayan fallado).
  con_reembolsos?: boolean;
  // Quién registró el cobro (en caja) o quién pagó en línea.
  registrado_por?: string | null;
  // Cobro en caja con error (ADR 0086/0087): su forma de caja y qué se puede hacer.
  metodo_caja?: string | null;
  corregible?: boolean;
  anulable?: boolean;
}
interface Suscripcion {
  id: string;
  persona: string | null;
  producto: string | null;
  precio_minor: number | null;
  moneda: string | null;
  proxima_cobro_en: string | null;
  estado: string;
  // Pago automático: la tarjeta con la que se cobra sola (y el último rechazo).
  pago_automatico: {
    marca: string | null;
    ultimos4: string | null;
    expira: string | null;
    error: string | null;
  } | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeRegularizar = computed(() => sesion.puede("ordenes.gestionar"));
const puedeReembolsar = computed(() => sesion.puede("pagos.reembolsar"));

const pendientes = ref<Pendiente[]>([]);
const metaPendientes = ref<MetaPendientes | null>(null);
const paginaPendientes = ref(1);
const morosos = ref<Moroso[]>([]);
const pagos = ref<Pago[]>([]);
const suscripciones = ref<Suscripcion[]>([]);
const pagoAutomaticoDisponible = ref(false);
const avisoRenovacion = ref<string | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const accionando = ref<string | null>(null);

// Modal de reembolso.
const reembolsando = ref<Pago | null>(null);
const rMonto = ref("");
const rMotivo = ref("");
const rRevertir = ref(true);
// Pago en línea cuyo dinero el negocio ya devolvió por fuera (no se pide a la pasarela).
const rManual = ref(false);
const rProcesando = ref(false);
const PASARELAS_EN_LINEA = ["stripe", "openpay", "mercadopago"];
const avisoReembolso = ref<string | null>(null);
const porConciliar = ref<InstanceType<typeof PorConciliar> | null>(null);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
function fechaHora(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(iso));
}
function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  // Fecha-solo (YYYY-MM-DD) en hora local para no restar un día; datetime tal cual.
  const d = iso.includes("T") ? new Date(iso) : new Date(`${iso}T00:00:00`);
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(d);
}

/**
 * Cobros se divide en vistas (`?vista=`): por cobrar (morosos y cargos recurrentes),
 * movimientos (pagos y reembolsos), conciliación y caja. Cada una carga solo lo
 * suyo al abrirse; la conciliación y la caja cargan sus propios datos.
 */
type VistaCobros = "por-cobrar" | "movimientos" | "conciliacion" | "caja";
const VISTAS: VistaCobros[] = [
  "por-cobrar",
  "movimientos",
  "conciliacion",
  "caja",
];
const route = useRoute();
const vista = computed<VistaCobros>(() => {
  const v = route.query.vista;
  return typeof v === "string" && (VISTAS as string[]).includes(v)
    ? (v as VistaCobros)
    : "por-cobrar";
});
const TITULOS: Record<VistaCobros, string> = {
  "por-cobrar": "nav.vistas.porCobrar",
  movimientos: "nav.vistas.movimientos",
  conciliacion: "nav.vistas.conciliacion",
  caja: "nav.vistas.caja",
};

async function cargar(): Promise<void> {
  error.value = null;
  if (vista.value === "conciliacion" || vista.value === "caja") {
    cargando.value = false;
    return;
  }
  cargando.value = true;
  try {
    if (vista.value === "movimientos") {
      const p = await api.get<{ data: Pago[] }>(`${base.value}/pagos`);
      pagos.value = p.data.data;
    } else {
      const [pe, d, s] = await Promise.all([
        api.get<{ data: Pendiente[]; meta: MetaPendientes }>(
          `${base.value}/cobranza/pendientes`,
          { params: { page: paginaPendientes.value } },
        ),
        api.get<{ data: Moroso[] }>(`${base.value}/dunning`),
        api.get<{
          data: Suscripcion[];
          pago_automatico_disponible?: boolean;
        }>(`${base.value}/suscripciones`),
      ]);
      pendientes.value = pe.data.data;
      metaPendientes.value = pe.data.meta;
      paginaPendientes.value = pe.data.meta.page;
      morosos.value = d.data.data;
      suscripciones.value = s.data.data;
      pagoAutomaticoDisponible.value =
        s.data.pago_automatico_disponible === true;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// Otra página de lo que se debe (sin recargar mora ni renovaciones).
async function irPendientes(n: number): Promise<void> {
  paginaPendientes.value = n;
  try {
    const { data } = await api.get<{
      data: Pendiente[];
      meta: MetaPendientes;
    }>(`${base.value}/cobranza/pendientes`, { params: { page: n } });
    pendientes.value = data.data;
    metaPendientes.value = data.meta;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

// Qué se debe, en una línea: la cita (con quién, cuándo) o la fecha de la compra.
function detallePendiente(p: Pendiente): string {
  if (p.sesion) {
    return [
      p.sesion.profesional
        ? t("cobranza.pendientes.con", { nombre: p.sesion.profesional })
        : null,
      new Intl.DateTimeFormat("es-MX", {
        timeZone: p.sesion.zona_horaria ?? undefined,
        weekday: "short",
        day: "numeric",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
        hour12: false,
      }).format(new Date(p.sesion.inicia_en)),
      p.sesion.sucursal,
    ]
      .filter(Boolean)
      .join(" · ");
  }
  return t("cobranza.pendientes.comprada", { fecha: fecha(p.creada_en) });
}

// Registrar el pago de lo que se debe (en caja: efectivo, transferencia…).
const METODOS_CAJA = ["efectivo", "transferencia", "ventanilla"] as const;
const cobrando = ref<Pendiente | null>(null);
const cMetodo = ref<(typeof METODOS_CAJA)[number]>("efectivo");
const cReferencia = ref("");
const cProcesando = ref(false);
const avisoCobro = ref<string | null>(null);
function abrirCobro(p: Pendiente): void {
  cobrando.value = p;
  cMetodo.value = "efectivo";
  cReferencia.value = "";
  avisoCobro.value = null;
}
async function registrarPago(): Promise<void> {
  const p = cobrando.value;
  if (!p) {
    return;
  }
  cProcesando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/ordenes/${p.id}/liquidar`, {
      metodo: cMetodo.value,
      referencia: cReferencia.value.trim() || null,
    });
    avisoCobro.value = t("cobranza.pendientes.registrado", {
      nombre: p.persona?.nombre ?? "—",
      monto: dinero(p.total_minor, p.moneda),
    });
    cobrando.value = null;
    await irPendientes(paginaPendientes.value);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cProcesando.value = false;
  }
}

function marca(m: string | null): string {
  return m ? m.charAt(0).toUpperCase() + m.slice(1) : "";
}

// Correo al alumno con el enlace para activar su pago automático.
async function invitarPagoAutomatico(s: Suscripcion): Promise<void> {
  accionando.value = s.id;
  error.value = null;
  avisoRenovacion.value = null;
  try {
    await api.post(
      `${base.value}/suscripciones/${s.id}/pago-automatico/solicitar`,
      {},
    );
    avisoRenovacion.value = t("pagoAutomatico.invitado");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = null;
  }
}

async function quitarPagoAutomatico(s: Suscripcion): Promise<void> {
  if (
    !(await confirmar(t("pagoAutomatico.confirmarQuitarNegocio"), {
      peligro: true,
    }))
  ) {
    return;
  }
  accionando.value = s.id;
  error.value = null;
  try {
    await api.delete(`${base.value}/suscripciones/${s.id}/pago-automatico`);
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = null;
  }
}

async function regularizar(m: Moroso): Promise<void> {
  if (m.acuerdo === null) {
    return;
  }
  if (
    !(await confirmar(
      t("confirmaciones.regularizar", { persona: m.persona?.nombre ?? "" }),
      { aceptar: t("confirmaciones.regularizarAceptar") },
    ))
  ) {
    return;
  }
  accionando.value = m.id;
  error.value = null;
  try {
    await api.post(`${base.value}/acuerdos/${m.acuerdo}/regularizar`, {});
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = null;
  }
}

// Reembolsos ya hechos de un pago (quién, cuánto, cuándo y por qué).
interface Reembolso {
  id: string;
  monto_minor: number;
  moneda: string;
  estado: string;
  motivo: string | null;
  revirtio_creditos: boolean;
  via?: string | null;
  // Por qué no se hizo (fallida) o por qué no se sabe aún (sin confirmar).
  motivo_fallo?: string | null;
  actor: string | null;
  fecha: string | null;
}
const reembolsosDe = ref<string | null>(null);
const reembolsos = ref<Reembolso[]>([]);
async function verReembolsos(p: Pago): Promise<void> {
  if (reembolsosDe.value === p.id) {
    reembolsosDe.value = null;
    return;
  }
  try {
    const { data } = await api.get<{ data: Reembolso[] }>(
      `${base.value}/pagos/${p.id}/reembolsos`,
    );
    reembolsos.value = data.data;
    reembolsosDe.value = p.id;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

// Una llave por intento: repetir el envío (doble clic) no devuelve dos veces.
const rLlave = ref("");

// Cobro que se está corrigiendo (forma de pago o anulación) en la lista.
const corrigiendo = ref<string | null>(null);
async function alCorregirCobro(): Promise<void> {
  corrigiendo.value = null;
  await cargar();
}

function abrirReembolso(p: Pago): void {
  reembolsando.value = p;
  rLlave.value = crypto.randomUUID();
  rMonto.value = String(p.reembolsable_minor / 100);
  rMotivo.value = "";
  rRevertir.value = true;
  rManual.value = false;
}

async function reembolsar(): Promise<void> {
  const p = reembolsando.value;
  if (p === null || rMotivo.value.trim() === "") {
    return;
  }
  rProcesando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: { estado: string } }>(
      `${base.value}/pagos/${p.id}/reembolsos`,
      {
        monto_minor: Math.round(Number(rMonto.value) * 100),
        motivo: rMotivo.value.trim(),
        revertir_creditos: rRevertir.value,
        manual: rManual.value,
      },
      { headers: { "Idempotency-Key": rLlave.value } },
    );
    avisoReembolso.value =
      data.data.estado === "pendiente"
        ? t("reembolsosPago.enProceso")
        : data.data.estado === "incierto"
          ? t("reembolsosPago.sinConfirmar")
          : null;
    porConciliar.value?.cargar();
    reembolsando.value = null;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    rProcesando.value = false;
  }
}

// ---- Indicadores y filtros (patrón de los listados) ----
const busqueda = ref("");
const filtroEstado = ref<"" | "aprobado" | "otros">("");
const pagosVisibles = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  return pagos.value.filter(
    (p) =>
      (q === "" || (p.persona ?? "").toLowerCase().includes(q)) &&
      (filtroEstado.value === "" ||
        (filtroEstado.value === "aprobado") === (p.estado === "aprobado")),
  );
});
function suma(lista: number[]): string {
  return dinero(
    lista.reduce((a, b) => a + b, 0),
    pagos.value[0]?.moneda ?? "MXN",
  );
}
const indicadores = computed<Indicador[]>(() => {
  if (vista.value === "movimientos") {
    const aprobados = pagos.value.filter((p) => p.estado === "aprobado");
    const otros = pagos.value.length - aprobados.length;
    return [
      {
        clave: "cobros",
        etiqueta: t("cobranzaVisual.kpi.cobros"),
        valor: String(aprobados.length),
        icono: "ventas",
      },
      {
        clave: "cobrado",
        etiqueta: t("cobranzaVisual.kpi.cobrado"),
        valor: suma(aprobados.map((p) => p.monto_minor)),
        icono: "dinero",
      },
      {
        clave: "reembolsado",
        etiqueta: t("cobranzaVisual.kpi.reembolsado"),
        valor: suma(pagos.value.map((p) => p.reembolsado_minor)),
        icono: "intercambio",
      },
      {
        clave: "revisar",
        etiqueta: t("cobranzaVisual.kpi.sinAprobar"),
        valor: String(otros),
        icono: "reloj",
        aviso: otros > 0,
      },
    ];
  }
  if (vista.value === "por-cobrar") {
    const automaticos = suscripciones.value.filter(
      (x) => x.pago_automatico !== null,
    ).length;
    const debe = metaPendientes.value;
    return [
      {
        clave: "porCobrar",
        etiqueta: t("cobranzaVisual.kpi.porCobrar"),
        valor:
          debe && debe.por_cobrar.length > 0
            ? debe.por_cobrar
                .map((m) => dinero(m.total_minor, m.moneda))
                .join(" + ")
            : dinero(0, "MXN"),
        icono: "dinero",
        aviso: (debe?.total ?? 0) > 0,
      },
      {
        clave: "mora",
        etiqueta: t("cobranzaVisual.kpi.enMora"),
        valor: String(morosos.value.length),
        icono: "facturas",
        aviso: morosos.value.length > 0,
      },
      {
        clave: "renovaciones",
        etiqueta: t("cobranzaVisual.kpi.renovaciones"),
        valor: String(suscripciones.value.length),
        icono: "reloj",
      },
      {
        clave: "automatico",
        etiqueta: t("cobranzaVisual.kpi.automatico"),
        valor: t("cobranzaVisual.deTotal", {
          n: automaticos,
          total: suscripciones.value.length,
        }),
        icono: "hecho",
      },
    ];
  }
  return [];
});
const TONO_PAGO: Record<string, string> = {
  aprobado: "var(--exito)",
  reembolsado: "var(--texto-suave)",
  anulado: "var(--texto-suave)",
  fallido: "var(--error)",
};

watch(vista, cargar, { immediate: true });
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion :titulo="$t(TITULOS[vista])" />

    <p
      v-if="avisoReembolso"
      class="mt-4 text-sm"
      role="status"
      style="color: var(--aviso)"
    >
      {{ avisoReembolso }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <TarjetasIndicadores
        v-if="indicadores.length > 0"
        class="mt-6"
        :tarjetas="indicadores"
      />
      <template v-if="vista === 'por-cobrar'">
        <p
          v-if="avisoCobro"
          class="mt-4 text-sm"
          role="status"
          :style="{ color: 'var(--exito)' }"
        >
          {{ avisoCobro }}
        </p>
        <!-- Lo que ya se debe: compras sin pagar y citas o clases ya pasadas -->
        <h2 class="mt-8 font-medium">
          {{ $t("cobranza.pendientes.titulo") }}
        </h2>
        <p
          v-if="metaPendientes && metaPendientes.proximas > 0"
          class="mt-1 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{
            $t(
              "cobranza.pendientes.proximas",
              { n: metaPendientes.proximas },
              metaPendientes.proximas,
            )
          }}
        </p>
        <EstadoVacio
          v-if="pendientes.length === 0"
          class="tu-card mt-3"
          icono="hecho"
          :titulo="$t('cobranza.pendientes.vacio')"
        />
        <div
          v-else
          class="mt-3 tu-card overflow-x-auto"
          data-prueba="pendientes"
        >
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>{{ $t("cobranza.pendientes.colCliente") }}</th>
                <th>{{ $t("cobranza.pendientes.colConcepto") }}</th>
                <th class="text-right">{{ $t("cobranza.colMonto") }}</th>
                <th class="text-right"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in pendientes" :key="p.id">
                <td>
                  <RouterLink
                    v-if="p.persona"
                    :to="{
                      name: 'ficha-miembro',
                      params: { id: p.persona.id },
                    }"
                    class="font-medium hover:underline"
                    >{{ p.persona.nombre }}</RouterLink
                  >
                  <span v-else>—</span>
                </td>
                <td>
                  <span class="block">{{ p.concepto ?? "—" }}</span>
                  <span
                    class="block text-xs first-letter:uppercase"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ detallePendiente(p) }}</span
                  >
                </td>
                <td class="text-right tabular-nums font-medium">
                  {{ dinero(p.total_minor, p.moneda) }}
                </td>
                <td class="text-right">
                  <button
                    v-if="puedeRegularizar"
                    type="button"
                    class="tu-btn tu-btn-fantasma text-sm"
                    data-prueba="registrar-pago"
                    @click="abrirCobro(p)"
                  >
                    {{ $t("cobranza.pendientes.registrar") }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <PaginacionListado
            v-if="metaPendientes && metaPendientes.ultima_pagina > 1"
            :page="metaPendientes.page"
            :ultima-pagina="metaPendientes.ultima_pagina"
            :total="metaPendientes.total"
            :per-page="metaPendientes.per_page"
            @ir="irPendientes"
          />
        </div>

        <!-- Morosos (dunning) -->
        <h2 class="mt-8 font-medium">{{ $t("cobranza.morosos") }}</h2>
        <EstadoVacio
          v-if="morosos.length === 0"
          class="tu-card mt-3"
          icono="hecho"
          :titulo="$t('cobranza.sinMorosos')"
        />
        <div v-else class="mt-3 tu-card overflow-x-auto">
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>
                  {{ $t("cobranza.colAlumno") }}
                </th>
                <th>
                  {{ $t("cobranza.colEstado") }}
                </th>
                <th class="text-right hidden sm:table-cell">
                  {{ $t("cobranza.colIntentos") }}
                </th>
                <th class="hidden md:table-cell">
                  {{ $t("cobranza.colGracia") }}
                </th>
                <th class="text-right"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in morosos" :key="m.id">
                <td>
                  <span class="font-semibold">{{
                    m.persona?.nombre ?? "—"
                  }}</span>
                  <span
                    v-if="m.ultimo_motivo"
                    class="block text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ m.ultimo_motivo }}</span
                  >
                </td>
                <td>
                  <span
                    class="tu-pildora"
                    :style="{
                      '--tono':
                        m.estado === 'suspendido'
                          ? 'var(--error)'
                          : 'var(--aviso)',
                    }"
                  >
                    {{ $t(`cobranza.estados.${m.estado}`) }}
                  </span>
                </td>
                <td class="text-right hidden sm:table-cell">
                  {{ m.intentos }}
                </td>
                <td
                  class="hidden md:table-cell"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ fecha(m.gracia_hasta) }}
                </td>
                <td class="text-right">
                  <button
                    v-if="puedeRegularizar && m.acuerdo"
                    class="tu-enlace text-sm"
                    type="button"
                    :disabled="accionando === m.id"
                    @click="regularizar(m)"
                  >
                    {{
                      accionando === m.id
                        ? $t("cobranza.regularizando")
                        : $t("cobranza.regularizar")
                    }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <!-- Por conciliar: lo del dinero que alguien debe revisar -->
      <PorConciliar
        v-if="vista === 'conciliacion' && sesion.puede('facturacion.ver')"
        ref="porConciliar"
        class="mt-6"
        @cambio="cargar"
      />

      <!-- Corte de caja: movimientos por fecha y por quién -->
      <CorteDeCaja
        v-if="vista === 'caja' && sesion.puede('facturacion.ver')"
        class="mt-6"
      />

      <!-- Pagos / reembolsos -->
      <template v-if="vista === 'movimientos'">
        <EstadoVacio
          v-if="pagos.length === 0"
          class="tu-card mt-5"
          icono="dinero"
          :titulo="$t('cobranza.sinPagos')"
        />
        <div v-else class="mt-5 tu-card overflow-x-auto">
          <div class="tu-filtros">
            <label class="tu-buscar">
              <IconoNav nombre="buscar" :tam="16" />
              <input
                v-model="busqueda"
                type="search"
                class="tu-input"
                :placeholder="$t('cobranzaVisual.buscar')"
                :aria-label="$t('cobranzaVisual.buscar')"
                data-prueba="buscar-pago"
              />
            </label>
            <div class="tu-segmentado" role="group">
              <button
                v-for="f in ['', 'aprobado', 'otros'] as const"
                :key="f"
                type="button"
                :aria-pressed="filtroEstado === f"
                @click="filtroEstado = f"
              >
                {{ $t(`cobranzaVisual.filtro.${f || "todos"}`) }}
              </button>
            </div>
          </div>
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>
                  {{ $t("cobranza.colFecha") }}
                </th>
                <th>
                  {{ $t("cobranza.colAlumno") }}
                </th>
                <th class="text-right">
                  {{ $t("cobranza.colMonto") }}
                </th>
                <th>
                  {{ $t("cobranza.colEstado") }}
                </th>
                <th class="text-right"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="p in pagosVisibles" :key="p.id">
                <tr>
                  <td
                    class="whitespace-nowrap"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ fechaHora(p.fecha) }}
                  </td>
                  <td>
                    <span class="font-semibold">{{ p.persona ?? "—" }}</span>
                    <span
                      v-if="p.registrado_por"
                      class="block text-xs"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{
                        $t("corteCaja.registro", { quien: p.registrado_por })
                      }}</span
                    >
                  </td>
                  <td class="text-right">
                    {{ dinero(p.monto_minor, p.moneda) }}
                    <span
                      v-if="p.con_reembolsos && !puedeReembolsar"
                      class="block ml-auto text-xs"
                      :style="{ color: 'var(--texto-suave)' }"
                    >
                      −{{ dinero(p.reembolsado_minor, p.moneda) }}
                    </span>
                    <button
                      v-else-if="p.con_reembolsos"
                      type="button"
                      class="block ml-auto text-xs underline-offset-2 hover:underline"
                      :style="{ color: 'var(--texto-suave)' }"
                      :aria-expanded="reembolsosDe === p.id"
                      :title="
                        reembolsosDe === p.id
                          ? $t('reembolsosPago.ocultar')
                          : $t('reembolsosPago.ver')
                      "
                      @click="verReembolsos(p)"
                    >
                      −{{ dinero(p.reembolsado_minor, p.moneda) }}
                    </button>
                  </td>
                  <td>
                    <span
                      class="tu-pildora"
                      :style="{
                        '--tono': TONO_PAGO[p.estado] ?? 'var(--aviso)',
                      }"
                      >{{ $t(`cobranza.pagoEstados.${p.estado}`) }}</span
                    >
                  </td>
                  <td class="text-right">
                    <span class="inline-flex flex-wrap justify-end gap-x-3">
                      <button
                        v-if="p.corregible || p.anulable"
                        class="tu-enlace text-sm"
                        type="button"
                        :aria-expanded="corrigiendo === p.id"
                        @click="
                          corrigiendo = corrigiendo === p.id ? null : p.id
                        "
                      >
                        {{ $t("cobranza.corregir") }}
                      </button>
                      <button
                        v-if="puedeReembolsar && p.reembolsable_minor > 0"
                        class="tu-enlace text-sm"
                        type="button"
                        @click="abrirReembolso(p)"
                      >
                        {{ $t("cobranza.reembolsar") }}
                      </button>
                    </span>
                  </td>
                </tr>
                <!-- Cobro en caja con error: corregir la forma o anularlo -->
                <tr v-if="corrigiendo === p.id">
                  <td colspan="5" class="tu-celda-detalle">
                    <CorregirCobro
                      :base="base"
                      :pago="{
                        id: p.id,
                        metodo: p.metodo_caja ?? null,
                        corregible: p.corregible === true,
                        anulable: p.anulable === true,
                      }"
                      @cambiado="alCorregirCobro"
                    />
                  </td>
                </tr>
                <tr v-if="reembolsosDe === p.id">
                  <td colspan="5" class="tu-celda-detalle">
                    <div
                      class="rounded-xl border px-4 text-xs"
                      :style="{
                        borderColor: 'var(--borde)',
                        background: 'var(--fondo)',
                      }"
                    >
                      <div
                        v-for="r in reembolsos"
                        :key="r.id"
                        class="flex items-center justify-between gap-3 border-t py-2 first:border-t-0"
                        :style="{ borderColor: 'var(--borde)' }"
                      >
                        <span class="min-w-0">
                          <span class="block font-medium">{{
                            r.motivo ?? "—"
                          }}</span>
                          <span
                            class="block"
                            :style="{ color: 'var(--texto-suave)' }"
                            >{{ fechaHora(r.fecha) }}
                            <template v-if="r.actor">
                              ·
                              {{
                                $t("reembolsosPago.por", { actor: r.actor })
                              }}</template
                            >
                            <template v-if="r.via">
                              ·
                              {{ $t(`reembolsosPago.via.${r.via}`) }}</template
                            >
                            <template v-if="r.revirtio_creditos">
                              ·
                              {{
                                $t("reembolsosPago.creditosRevertidos")
                              }}</template
                            ></span
                          >
                          <span
                            v-if="r.motivo_fallo"
                            class="block"
                            style="color: var(--error)"
                            >{{ r.motivo_fallo }}</span
                          >
                        </span>
                        <span class="flex items-center gap-2 shrink-0">
                          <span class="font-semibold tabular-nums">{{
                            dinero(r.monto_minor, r.moneda)
                          }}</span>
                          <span
                            class="tu-pildora"
                            :style="{
                              '--tono':
                                r.estado === 'aprobado'
                                  ? 'var(--exito)'
                                  : 'var(--aviso)',
                            }"
                            >{{
                              $t(`reembolsosPago.estados.${r.estado}`)
                            }}</span
                          >
                        </span>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
          <p v-if="pagosVisibles.length === 0" class="tu-sin-resultados">
            {{ $t("cobranzaVisual.sinResultados") }}
          </p>
        </div>
      </template>

      <!-- Próximas renovaciones (cobro recurrente) -->
      <template v-if="vista === 'por-cobrar'">
        <h2 class="mt-8 font-medium">
          {{ $t("cobranza.renovaciones") }}
        </h2>
        <p
          v-if="avisoRenovacion"
          class="mt-2 text-sm"
          role="status"
          :style="{ color: 'var(--exito)' }"
        >
          {{ avisoRenovacion }}
        </p>
        <EstadoVacio
          v-if="suscripciones.length === 0"
          class="tu-card mt-3"
          icono="reloj"
          :titulo="$t('cobranza.sinRenovaciones')"
        />
        <div v-else class="mt-3 tu-card overflow-x-auto">
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>
                  {{ $t("cobranza.colAlumno") }}
                </th>
                <th class="hidden sm:table-cell">
                  {{ $t("cobranza.colMembresia") }}
                </th>
                <th class="text-right">
                  {{ $t("cobranza.colMonto") }}
                </th>
                <th>
                  {{ $t("cobranza.colProxima") }}
                </th>
                <th>
                  {{ $t("pagoAutomatico.colCobro") }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in suscripciones" :key="s.id">
                <td class="font-semibold">{{ s.persona ?? "—" }}</td>
                <td
                  class="hidden sm:table-cell"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ s.producto ?? "—" }}
                </td>
                <td class="text-right">
                  {{
                    s.precio_minor !== null
                      ? dinero(s.precio_minor, s.moneda ?? "MXN")
                      : "—"
                  }}
                </td>
                <td>
                  {{ fecha(s.proxima_cobro_en) }}
                  <span
                    v-if="s.estado !== 'activo'"
                    class="tu-pildora ml-1"
                    :style="{ '--tono': 'var(--error)' }"
                    >{{ $t(`cobranza.estados.${s.estado}`, s.estado) }}</span
                  >
                </td>
                <td>
                  <template v-if="s.pago_automatico">
                    <span
                      class="tu-pildora"
                      :style="{ '--tono': 'var(--exito)' }"
                      >{{
                        $t("pagoAutomatico.tarjeta", {
                          marca: marca(s.pago_automatico.marca),
                          ultimos4: s.pago_automatico.ultimos4 ?? "····",
                        })
                      }}</span
                    >
                    <button
                      v-if="puedeRegularizar"
                      type="button"
                      class="tu-enlace ml-2 text-xs"
                      :disabled="accionando !== null"
                      @click="quitarPagoAutomatico(s)"
                    >
                      {{ $t("pagoAutomatico.quitar") }}
                    </button>
                    <p
                      v-if="s.pago_automatico.error"
                      class="text-xs"
                      style="color: var(--error)"
                    >
                      {{ s.pago_automatico.error }}
                    </p>
                  </template>
                  <template v-else>
                    <span :style="{ color: 'var(--texto-suave)' }">{{
                      $t("pagoAutomatico.pagoManual")
                    }}</span>
                    <button
                      v-if="puedeRegularizar && pagoAutomaticoDisponible"
                      type="button"
                      class="tu-enlace ml-2 text-xs"
                      :disabled="accionando !== null"
                      @click="invitarPagoAutomatico(s)"
                    >
                      {{ $t("pagoAutomatico.invitar") }}
                    </button>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>

    <!-- Modal de reembolso -->
    <ModalDialogo
      :abierto="reembolsando !== null"
      :titulo="$t('cobranza.reembolso.titulo')"
      @cerrar="reembolsando = null"
    >
      <template v-if="reembolsando">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ reembolsando.persona ?? "—" }} ·
          {{
            $t("cobranza.reembolso.reembolsable", {
              monto: dinero(
                reembolsando.reembolsable_minor,
                reembolsando.moneda,
              ),
            })
          }}
        </p>
        <form
          id="form-reembolso"
          class="mt-4 grid gap-4 sm:grid-cols-2"
          @submit.prevent="reembolsar"
        >
          <div>
            <label class="tu-label" for="rm">{{
              $t("cobranza.reembolso.monto")
            }}</label>
            <input
              id="rm"
              v-model="rMonto"
              class="tu-input"
              type="number"
              min="0"
              step="0.01"
              required
            />
          </div>
          <div>
            <label class="tu-label" for="rmt">{{
              $t("cobranza.reembolso.motivo")
            }}</label>
            <input
              id="rmt"
              v-model="rMotivo"
              class="tu-input"
              required
              :placeholder="$t('cobranza.reembolso.motivoPh')"
            />
          </div>
          <label
            class="flex items-center justify-between gap-3 text-sm sm:col-span-2"
          >
            <span>
              {{ $t("cobranza.reembolso.revertir") }}
              <span
                class="block text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ $t("cobranza.reembolso.revertirAyuda") }}</span
              >
            </span>
            <input v-model="rRevertir" type="checkbox" class="h-5 w-5" />
          </label>
          <label
            v-if="PASARELAS_EN_LINEA.includes(reembolsando.proveedor ?? '')"
            class="flex items-center justify-between gap-3 text-sm sm:col-span-2"
          >
            <span>
              {{ $t("reembolsosPago.manual") }}
              <span
                class="block text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ $t("reembolsosPago.manualAyuda") }}</span
              >
            </span>
            <input v-model="rManual" type="checkbox" class="h-5 w-5" />
          </label>
        </form>
      </template>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          @click="reembolsando = null"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <button
          class="tu-btn tu-btn-primario"
          type="submit"
          form="form-reembolso"
          :disabled="rProcesando || rMotivo.trim() === ''"
        >
          {{
            rProcesando
              ? $t("cobranza.reembolso.procesando")
              : $t("cobranza.reembolso.confirmar")
          }}
        </button>
      </template>
    </ModalDialogo>
    <!-- Registrar el pago de lo que se debe -->
    <ModalDialogo
      :abierto="cobrando !== null"
      :titulo="$t('cobranza.pendientes.registrarTitulo')"
      tam="md"
      @cerrar="cobrando = null"
    >
      <form v-if="cobrando" class="space-y-4" @submit.prevent="registrarPago">
        <p class="text-sm">
          <span class="font-medium">{{ cobrando.persona?.nombre ?? "—" }}</span>
          · {{ cobrando.concepto ?? "—" }}
        </p>
        <p class="text-2xl font-semibold tabular-nums">
          {{ dinero(cobrando.total_minor, cobrando.moneda) }}
        </p>
        <div>
          <p class="tu-label">{{ $t("ventas.vender.metodo") }}</p>
          <div class="tu-segmentado w-full" role="group">
            <button
              v-for="m in METODOS_CAJA"
              :key="m"
              type="button"
              class="flex-1"
              :aria-pressed="cMetodo === m"
              @click="cMetodo = m"
            >
              {{ $t(`ventas.metodos.${m}`) }}
            </button>
          </div>
        </div>
        <div>
          <label class="tu-label" for="cobro-ref">{{
            $t("cobranza.pendientes.referencia")
          }}</label>
          <input
            id="cobro-ref"
            v-model="cReferencia"
            class="tu-input"
            maxlength="255"
          />
        </div>
        <div class="flex justify-end gap-2">
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            @click="cobrando = null"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            type="submit"
            class="tu-btn tu-btn-primario"
            :disabled="cProcesando"
            data-prueba="confirmar-pago"
          >
            {{ $t("cobranza.pendientes.registrar") }}
          </button>
        </div>
      </form>
    </ModalDialogo>
  </section>
</template>
