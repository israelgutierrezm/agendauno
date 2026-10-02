<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import CambiarHorario from "@/components/CambiarHorario.vue";
import ConfirmarCancelacion from "@/components/ConfirmarCancelacion.vue";
import CorregirCobro from "@/components/CorregirCobro.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import {
  aHora,
  COLOR_ESTADO_CITA,
  duracionMin,
  estadoCita,
  minutosLocal,
  tonoServicio,
  type SesionAgenda,
} from "@/lib/agenda";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Detalle de una CITA para recepción: quién (con su contacto y su ficha), cuándo,
 * qué servicio, con quién, dónde y cómo va. En pestañas: la asistencia (llegó, no
 * asistió o cancelarla) con el resumen del cobro; el cobro y sus movimientos; y el
 * historial de la cita (lo que pasó y quién lo hizo).
 *
 * Lo que mueve dinero o créditos se confirma antes (cobrar, marcar asistencia) y se
 * puede corregir después: la asistencia (llegó ↔ no asistió, el crédito se ajusta) y
 * un cobro en caja (su forma de pago o anularlo), si el negocio lo permite (ADR
 * 0086/0087). Cada acción se guarda al hacerla; «Listo» solo cierra.
 */
const props = defineProps<{
  abierto: boolean;
  base: string;
  sesion: SesionAgenda | null;
  catalogo: string[];
  puedeMarcar: boolean;
  puedeCobrar: boolean;
  puedeCancelar: boolean;
  // Con quién se puede mover la cita (2.1).
  profesionales?: { id: string; nombre: string }[];
}>();

const emit = defineEmits<{ cerrar: []; cambiada: [] }>();

const { t, te } = useI18n();
const toast = useToastStore();
const sesionTenant = useSesionTenantStore();

const METODOS = ["efectivo", "transferencia", "manual"] as const;
const metodo = ref<(typeof METODOS)[number]>("efectivo");
const accionando = ref(false);

type Pestana = "asistencia" | "cobro" | "historial";
const pestana = ref<Pestana>("asistencia");
const PESTANAS: { clave: Pestana; icono: string }[] = [
  { clave: "asistencia", icono: "agenda" },
  { clave: "cobro", icono: "dinero" },
  { clave: "historial", icono: "reloj" },
];

const cita = computed(() => props.sesion?.cita ?? null);
const cliente = computed(
  () => cita.value?.cliente ?? t("agendaVisual.profesionales.sinCliente"),
);
const verFicha = computed(
  () => cita.value?.cliente_id != null && sesionTenant.puede("miembros.ver"),
);
const verHistorial = computed(() => sesionTenant.puede("reservas.ver"));
const estado = computed(() =>
  props.sesion !== null ? estadoCita(props.sesion, new Date()) : "cancelada",
);
const tono = computed(() =>
  props.sesion !== null
    ? tonoServicio(props.sesion.oferta_id, props.catalogo, props.sesion.oferta)
    : null,
);
const fechaLarga = computed(() => {
  const s = props.sesion;
  return s === null
    ? ""
    : new Intl.DateTimeFormat("es-MX", {
        timeZone: s.zona_horaria,
        weekday: "long",
        day: "numeric",
        month: "long",
        year: "numeric",
      }).format(new Date(s.inicia_en));
});
const horas = computed(() => {
  const s = props.sesion;
  if (s === null) {
    return "";
  }
  const ini = minutosLocal(s.inicia_en, s.zona_horaria);
  const dur = duracionMin(s);
  return `${aHora(ini)} – ${aHora(ini + dur)} (${t("detalleCita.minutos", { n: dur })})`;
});
function dinero(minor: number, decimales = 0): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
    minimumFractionDigits: decimales,
    maximumFractionDigits: decimales,
  }).format(minor / 100);
}
const precio = computed(() =>
  props.sesion?.oferta_precio_clase
    ? dinero(props.sesion.oferta_precio_clase)
    : null,
);
const total = computed(() =>
  props.sesion?.oferta_precio_clase
    ? dinero(props.sesion.oferta_precio_clase, 2)
    : null,
);
const sucursal = computed(() =>
  [props.sesion?.sucursal, props.sesion?.sala].filter(Boolean).join(" · "),
);

// Falta cobrarla: pendiente de pago en línea o agendada por el negocio sin cobrar.
const porCobrar = computed(
  () =>
    cita.value !== null &&
    cita.value.orden_id != null &&
    (cita.value.estado === "pendiente_pago" || cita.value.por_cobrar === true),
);
function nombreMetodo(m: string | null | undefined): string {
  const llave = `agendaVisual.cita.metodos.${m ?? "manual"}`;
  return te(llave) ? t(llave) : (m ?? "");
}
// Cómo va el pago: color (solo lo que pide atención) y texto.
const pagoEstado = computed<{ texto: string; color: string } | null>(() => {
  const c = cita.value;
  if (c === null || c.orden_id == null) {
    return null;
  }
  if (c.estado === "pendiente_pago") {
    return { texto: t("detalleCita.cobro.porPagar"), color: "var(--aviso)" };
  }
  if (c.por_cobrar === true) {
    return { texto: t("detalleCita.cobro.porCobrar"), color: "var(--aviso)" };
  }
  return { texto: t("detalleCita.cobro.pagada"), color: "var(--exito)" };
});
const activa = computed(
  () =>
    estado.value !== "cancelada" &&
    estado.value !== "completada" &&
    estado.value !== "no_asistio",
);
const sePuedeCobrar = computed(
  () => activa.value && porCobrar.value && props.puedeCobrar,
);
// Reprogramar o cancelar: aún sin asistencia marcada.
const sePuedeMover = computed(
  () =>
    props.puedeCancelar &&
    activa.value &&
    cita.value !== null &&
    !cita.value.asistencia,
);

async function accion(
  fn: () => Promise<unknown>,
  mensaje: string,
): Promise<void> {
  accionando.value = true;
  try {
    await fn();
    toast.exito(mensaje);
    emit("cambiada");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = false;
  }
}

// ---- Asistencia: llegó / no asistió (y corregir una ya marcada) ----
type Asistencia = "presente" | "ausente";
const marcable = computed(
  () =>
    props.puedeMarcar &&
    cita.value !== null &&
    cita.value.estado === "confirmada",
);
const subtituloLlego = computed(() => {
  if (cita.value?.asistencia !== "presente") {
    return t("detalleCita.asistencia.llegoAyuda");
  }
  return estado.value === "llego"
    ? t("detalleCita.asistencia.enEspera")
    : t(`agendaVisual.estadosCita.${estado.value}`);
});
async function marcar(asistencia: Asistencia): Promise<void> {
  const c = cita.value;
  if (c === null || c.asistencia === asistencia) {
    return;
  }
  const nombre = (e: string): string =>
    e === "presente"
      ? t("agendaVisual.cita.marcarLlegada")
      : t("agendaVisual.cita.noAsistio");
  // Marcar o corregir mueve créditos: se confirma con su efecto a la vista.
  const mensaje = c.asistencia
    ? t("agendaVisual.cita.confirmarCorreccion", {
        cliente: cliente.value,
        antes: nombre(c.asistencia),
        ahora: nombre(asistencia),
      })
    : asistencia === "presente"
      ? t("agendaVisual.cita.confirmarLlegada", { cliente: cliente.value })
      : t("agendaVisual.cita.confirmarNoAsistio", { cliente: cliente.value });
  if (
    !(await confirmar(mensaje, {
      aceptar: nombre(asistencia),
      peligro: asistencia === "ausente",
    }))
  ) {
    return;
  }
  void accion(
    () =>
      api.post(`${props.base}/reservas/${c.reserva_id}/asistencia`, {
        estado: asistencia,
      }),
    c.asistencia
      ? t("agendaVisual.cita.okCorregida")
      : asistencia === "presente"
        ? t("agendaVisual.cita.okLlego")
        : t("agendaVisual.cita.okNoAsistio"),
  );
}

// ---- Cobro en caja ----
async function cobrar(): Promise<void> {
  const c = cita.value;
  if (c === null || c.orden_id == null) {
    return;
  }
  // Un cobro en caja no se deshace: se confirma monto, forma y a quién.
  if (
    !(await confirmar(
      t("agendaVisual.cita.confirmarCobro", {
        monto: precio.value ?? "",
        metodo: nombreMetodo(metodo.value),
        cliente: cliente.value,
      }),
      { aceptar: t("agendaVisual.cita.cobrar", { monto: precio.value ?? "" }) },
    ))
  ) {
    return;
  }
  const orden = c.orden_id;
  void accion(
    () =>
      api.post(`${props.base}/ordenes/${orden}/liquidar`, {
        metodo: metodo.value,
      }),
    t("agendaVisual.cita.okCobrada"),
  );
}

// ---- Historial (y los movimientos del pago) ----
interface Hecho {
  tipo: string;
  fecha: string;
  actor: string | null;
  detalle: Record<string, string | number | boolean | null>;
}
const historial = ref<Hecho[] | null>(null);
const cargandoHistorial = ref(false);
const errorHistorial = ref(false);
async function cargarHistorial(): Promise<void> {
  const c = cita.value;
  if (c === null || !verHistorial.value) {
    return;
  }
  cargandoHistorial.value = true;
  errorHistorial.value = false;
  try {
    const { data } = await api.get<{ data: Hecho[] }>(
      `${props.base}/reservas/${c.reserva_id}/historial`,
    );
    historial.value = data.data;
  } catch {
    errorHistorial.value = true;
  } finally {
    cargandoHistorial.value = false;
  }
}
const PAGO = ["cobrada", "metodo_corregido", "cobro_anulado", "reembolsada"];
const movimientosPago = computed(() =>
  (historial.value ?? []).filter((h) => PAGO.includes(h.tipo)),
);
function tituloHecho(h: Hecho): string {
  const d = h.detalle;
  switch (h.tipo) {
    case "agendada":
      return t("detalleCita.historial.agendada");
    case "recordatorio":
      return t("detalleCita.historial.recordatorio", { horas: d.horas });
    case "reprogramada":
      return t("detalleCita.historial.reprogramada");
    case "cobrada":
      return d.en_caja
        ? `${t("detalleCita.historial.cobradaEnCaja")} · ${nombreMetodo(d.metodo as string | null)}`
        : t("detalleCita.historial.pagadaEnLinea");
    case "metodo_corregido":
      return t("detalleCita.historial.metodoCorregido");
    case "cobro_anulado":
      return t("detalleCita.historial.cobroAnulado");
    case "reembolsada":
      return t("detalleCita.historial.reembolsada");
    case "asistencia":
    case "asistencia_corregida": {
      const llave = `detalleCita.historial.${h.tipo === "asistencia" ? "asistencia" : "asistenciaCorregida"}.${d.estado}`;
      return te(llave) ? t(llave) : "";
    }
    case "cancelada":
      return t(
        `detalleCita.historial.cancelada.${d.por === "cliente" ? "cliente" : "negocio"}`,
      );
    default:
      return h.tipo;
  }
}
function detalleHecho(h: Hecho): string | null {
  const d = h.detalle;
  switch (h.tipo) {
    case "reprogramada":
      return d.de && d.a
        ? t("detalleCita.historial.deA", { de: d.de, a: d.a })
        : null;
    case "cobrada":
    case "reembolsada":
      return typeof d.monto_minor === "number"
        ? dinero(d.monto_minor, 2)
        : null;
    case "metodo_corregido":
      return [
        t("detalleCita.historial.deA", {
          de: nombreMetodo(d.de as string | null),
          a: nombreMetodo(d.a as string | null),
        }),
        d.motivo ? t("detalleCita.historial.motivo", { motivo: d.motivo }) : "",
      ]
        .filter(Boolean)
        .join(" · ");
    case "cobro_anulado":
      return d.motivo
        ? t("detalleCita.historial.motivo", { motivo: d.motivo })
        : null;
    default:
      return null;
  }
}
function cuando(h: Hecho): string {
  const fecha = new Intl.DateTimeFormat("es-MX", {
    timeZone: props.sesion?.zona_horaria,
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(h.fecha));
  return h.actor
    ? `${fecha} · ${t("detalleCita.historial.por", { actor: h.actor })}`
    : fecha;
}
watch(pestana, (p) => {
  if (p !== "asistencia" && historial.value === null) {
    void cargarHistorial();
  }
});

// ---- Reprogramar (2.1) y cancelar: en el cuerpo, en lugar de las pestañas ----
const reprogramando = ref(false);
const cancelando = ref(false);
function reprogramada(datos: { antes: string; ahora: string }): void {
  reprogramando.value = false;
  const zona = props.sesion?.zona_horaria ?? "America/Mexico_City";
  const f = (iso: string): string =>
    new Intl.DateTimeFormat("es-MX", {
      timeZone: zona,
      weekday: "short",
      day: "numeric",
      month: "short",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(iso));
  toast.exito(
    t("reprogramar.hecho", { antes: f(datos.antes), ahora: f(datos.ahora) }),
  );
  emit("cambiada");
}
function cancelar(por: "cliente" | "negocio" | null): void {
  const c = cita.value;
  if (c === null) {
    return;
  }
  void accion(
    () =>
      api.post(
        `${props.base}/reservas/${c.reserva_id}/cancelar`,
        por ? { por } : {},
      ),
    t("agendaVisual.cita.okCancelada"),
  ).then(() => {
    cancelando.value = false;
  });
}
function abrirCancelacion(): void {
  reprogramando.value = false;
  cancelando.value = true;
}
function abrirReprogramar(): void {
  cancelando.value = false;
  reprogramando.value = true;
}

watch(
  () => props.abierto,
  () => {
    metodo.value = "efectivo";
    pestana.value = "asistencia";
    historial.value = null;
    reprogramando.value = false;
    cancelando.value = false;
  },
);
// Tras un cambio llega la cita actualizada: el historial se vuelve a leer.
watch(
  () => props.sesion,
  () => {
    historial.value = null;
    if (props.abierto && pestana.value !== "asistencia") {
      void cargarHistorial();
    }
  },
);
</script>

<template>
  <ModalDialogo
    :abierto="abierto"
    :titulo="$t('agendaVisual.cita.titulo')"
    icono="agenda"
    tam="xl"
    @cerrar="emit('cerrar')"
  >
    <div v-if="sesion !== null" class="pc">
      <!-- Quién, cuándo y cómo va -->
      <div class="pc-cabeza">
        <div class="pc-persona">
          <AvatarIniciales :nombre="cita?.cliente" tam="xl" />
          <div class="min-w-0">
            <p class="pc-nombre">{{ cliente }}</p>
            <p v-if="cita?.telefono" class="pc-suave">{{ cita.telefono }}</p>
            <p v-if="cita?.email" class="pc-suave truncate">
              {{ cita.email }}
            </p>
            <RouterLink
              v-if="verFicha"
              :to="{ name: 'ficha-miembro', params: { id: cita?.cliente_id } }"
              class="pc-enlace"
              >{{ $t("detalleCita.verPerfil") }} →</RouterLink
            >
          </div>
        </div>
        <div class="pc-cuando">
          <span class="tu-cuadro-icono" aria-hidden="true">
            <IconoNav nombre="agenda" :tam="20" />
          </span>
          <div class="min-w-0">
            <p class="font-medium first-letter:uppercase">{{ fechaLarga }}</p>
            <p class="pc-suave">{{ horas }}</p>
          </div>
        </div>
        <div class="pc-estados">
          <span
            class="tu-pildora"
            :style="{ '--tono': COLOR_ESTADO_CITA[estado] }"
            data-prueba="estado-cita"
          >
            {{ $t(`agendaVisual.estadosCita.${estado}`) }}
          </span>
          <span
            v-if="pagoEstado"
            class="tu-pildora"
            :style="{ '--tono': pagoEstado.color }"
          >
            {{ pagoEstado.texto }}
          </span>
        </div>
      </div>

      <!-- Acciones de quien lo abre (p. ej. agregar a mi calendario) -->
      <slot name="acciones" />

      <!-- Qué, con quién, cuánto y dónde -->
      <dl class="tu-detalle-franja">
        <div>
          <span class="tu-cuadro-icono" aria-hidden="true">
            <IconoNav nombre="etiqueta" :tam="20" />
          </span>
          <div class="min-w-0">
            <dt>{{ $t("detalleCita.servicio") }}</dt>
            <dd class="flex items-center gap-1.5">
              <span
                v-if="tono"
                class="h-2 w-2 shrink-0 rounded-full"
                :style="{ background: tono.tinta }"
                aria-hidden="true"
              ></span
              >{{ sesion.oferta ?? "—" }}
            </dd>
          </div>
        </div>
        <div>
          <span class="tu-cuadro-icono" aria-hidden="true">
            <IconoNav nombre="instructores" :tam="20" />
          </span>
          <div class="min-w-0">
            <dt>{{ $t("detalleCita.profesional") }}</dt>
            <dd>{{ sesion.instructor ?? "—" }}</dd>
          </div>
        </div>
        <div>
          <span class="tu-cuadro-icono" aria-hidden="true">
            <IconoNav nombre="dinero" :tam="20" />
          </span>
          <div class="min-w-0">
            <dt>{{ $t("detalleCita.precio") }}</dt>
            <dd class="font-semibold">{{ precio ?? "—" }}</dd>
          </div>
        </div>
        <div>
          <span class="tu-cuadro-icono" aria-hidden="true">
            <IconoNav nombre="ubicacion" :tam="20" />
          </span>
          <div class="min-w-0">
            <dt>{{ $t("detalleCita.sucursal") }}</dt>
            <dd>{{ sucursal || "—" }}</dd>
          </div>
        </div>
      </dl>

      <!-- Reprogramar o cancelar: en lugar de las pestañas, con su confirmación -->
      <section v-if="reprogramando && cita !== null" class="tu-detalle-seccion">
        <CambiarHorario
          :url="`${base}/reservas/${cita.reserva_id}/reprogramar`"
          :zona="sesion.zona_horaria"
          :inicia-en="sesion.inicia_en"
          :profesionales="profesionales"
          :profesional-id="sesion.instructor_id"
          @hecho="reprogramada"
          @cerrar="reprogramando = false"
        />
      </section>
      <section
        v-else-if="cancelando && cita !== null"
        class="tu-detalle-seccion"
      >
        <ConfirmarCancelacion
          :url="`${base}/reservas/${cita.reserva_id}/cancelacion`"
          con-quien
          :ocupado="accionando"
          @confirmar="cancelar"
          @cerrar="cancelando = false"
        />
      </section>

      <template v-else>
        <div class="tu-pestanas" role="tablist">
          <template v-for="p in PESTANAS" :key="p.clave">
            <button
              v-if="p.clave === 'asistencia' || verHistorial"
              type="button"
              role="tab"
              class="inline-flex items-center gap-2"
              :aria-selected="pestana === p.clave"
              :aria-pressed="pestana === p.clave"
              @click="pestana = p.clave"
            >
              <IconoNav :nombre="p.icono" :tam="18" />
              {{ $t(`detalleCita.pestanas.${p.clave}`) }}
            </button>
          </template>
        </div>

        <!-- Asistencia y estado (con el resumen del cobro) -->
        <template v-if="pestana === 'asistencia'">
          <section class="tu-detalle-seccion">
            <header>
              <h3>{{ $t("detalleCita.asistencia.titulo") }}</h3>
              <p class="pc-suave">{{ $t("detalleCita.asistencia.ayuda") }}</p>
            </header>
            <div class="pc-opciones">
              <button
                type="button"
                class="pc-opcion"
                :class="{ 'pc-opcion-bien': cita?.asistencia === 'presente' }"
                :aria-pressed="cita?.asistencia === 'presente'"
                :disabled="
                  !marcable || accionando || cita?.asistencia === 'presente'
                "
                @click="marcar('presente')"
              >
                <span class="pc-opcion-icono" aria-hidden="true">
                  <IconoNav nombre="hecho" :tam="20" />
                </span>
                <span class="min-w-0">
                  <span class="pc-opcion-titulo">{{
                    $t("detalleCita.asistencia.llego")
                  }}</span>
                  <span class="pc-opcion-sub">{{ subtituloLlego }}</span>
                </span>
              </button>
              <button
                type="button"
                class="pc-opcion"
                :class="{ 'pc-opcion-mal': cita?.asistencia === 'ausente' }"
                :aria-pressed="cita?.asistencia === 'ausente'"
                :disabled="
                  !marcable || accionando || cita?.asistencia === 'ausente'
                "
                @click="marcar('ausente')"
              >
                <span class="pc-opcion-icono" aria-hidden="true">
                  <IconoNav nombre="cerrar" :tam="20" />
                </span>
                <span class="min-w-0">
                  <span class="pc-opcion-titulo">{{
                    $t("detalleCita.asistencia.noAsistio")
                  }}</span>
                  <span class="pc-opcion-sub">{{
                    $t("detalleCita.asistencia.noAsistioAyuda")
                  }}</span>
                </span>
              </button>
              <button
                type="button"
                class="pc-opcion"
                :disabled="!sePuedeMover || accionando"
                @click="abrirCancelacion"
              >
                <span class="pc-opcion-icono" aria-hidden="true">
                  <IconoNav nombre="ausente" :tam="20" />
                </span>
                <span class="min-w-0">
                  <span class="pc-opcion-titulo">{{
                    $t("detalleCita.asistencia.cancelada")
                  }}</span>
                  <span class="pc-opcion-sub">{{
                    $t("detalleCita.asistencia.canceladaAyuda")
                  }}</span>
                </span>
              </button>
            </div>

            <dl v-if="cita?.nota || cita?.asiste" class="pc-notas">
              <template v-if="cita?.asiste">
                <dt>{{ $t("detalleCita.asiste") }}</dt>
                <dd data-prueba="asiste">{{ cita.asiste }}</dd>
              </template>
              <template v-if="cita?.nota">
                <dt>{{ $t("detalleCita.notaCliente") }}</dt>
                <dd data-prueba="nota-cliente">{{ cita.nota }}</dd>
              </template>
            </dl>
          </section>
        </template>

        <!-- Cobro y pago: en las dos primeras pestañas -->
        <section v-if="pestana !== 'historial'" class="tu-detalle-seccion">
          <header>
            <h3>{{ $t("detalleCita.cobro.titulo") }}</h3>
            <p class="pc-suave">{{ $t("detalleCita.cobro.ayuda") }}</p>
          </header>
          <div class="pc-cobro">
            <div>
              <span class="pc-etiqueta">{{
                $t("detalleCita.cobro.total")
              }}</span>
              <span class="pc-total">{{
                cita?.orden_id != null ? (total ?? "—") : "—"
              }}</span>
            </div>
            <div>
              <span class="pc-etiqueta">{{
                $t("detalleCita.cobro.metodo")
              }}</span>
              <div
                v-if="sePuedeCobrar"
                class="tu-segmentado flex-wrap"
                role="group"
                :aria-label="$t('detalleCita.cobro.elige')"
              >
                <button
                  v-for="m in METODOS"
                  :key="m"
                  type="button"
                  :aria-pressed="metodo === m"
                  @click="metodo = m"
                >
                  {{ $t(`agendaVisual.cita.metodos.${m}`) }}
                </button>
              </div>
              <template v-else-if="cita?.pago">
                <span class="font-medium">{{
                  nombreMetodo(cita.pago.metodo)
                }}</span>
              </template>
              <template v-else>
                <span class="font-medium">—</span>
                <span class="pc-suave text-sm">{{
                  cita?.orden_id != null
                    ? $t("detalleCita.cobro.sinPago")
                    : $t("detalleCita.cobro.membresia")
                }}</span>
              </template>
            </div>
            <div>
              <span class="pc-etiqueta">{{
                $t("detalleCita.cobro.estado")
              }}</span>
              <span
                class="pc-estado-pago"
                :style="{
                  '--tono': pagoEstado?.color ?? 'var(--texto-suave)',
                }"
              >
                <span class="pc-punto" aria-hidden="true"></span>
                {{ pagoEstado?.texto ?? $t("detalleCita.cobro.sinCobro") }}
              </span>
            </div>
            <div v-if="sePuedeCobrar" class="pc-cobrar">
              <button
                type="button"
                class="tu-btn tu-btn-primario w-full"
                :disabled="accionando"
                @click="cobrar"
              >
                <IconoNav nombre="dinero" :tam="18" />
                {{ $t("detalleCita.cobro.registrar") }}
              </button>
            </div>
          </div>

          <!-- Cobro en caja con error: corregir la forma o anularlo (ADR 0086/0087) -->
          <CorregirCobro
            v-if="cita?.pago?.en_caja"
            :base="base"
            :pago="cita.pago"
            @cambiado="emit('cambiada')"
          />

          <!-- Lo que ha pasado con el pago -->
          <div v-if="pestana === 'cobro'" class="pc-movimientos">
            <h4>{{ $t("detalleCita.cobro.movimientos") }}</h4>
            <p v-if="cargandoHistorial" class="pc-suave text-sm">
              {{ $t("detalleCita.historial.cargando") }}
            </p>
            <p
              v-else-if="errorHistorial"
              class="text-sm"
              :style="{ color: 'var(--error)' }"
            >
              {{ $t("detalleCita.historial.error") }}
            </p>
            <p
              v-else-if="movimientosPago.length === 0"
              class="pc-suave text-sm"
            >
              {{ $t("detalleCita.cobro.sinMovimientos") }}
            </p>
            <ol v-else class="pc-linea">
              <li v-for="(h, i) in movimientosPago" :key="i">
                <span class="pc-linea-punto" aria-hidden="true"></span>
                <div class="min-w-0">
                  <p class="font-medium">{{ tituloHecho(h) }}</p>
                  <p v-if="detalleHecho(h)" class="text-sm">
                    {{ detalleHecho(h) }}
                  </p>
                  <p class="pc-suave text-xs">{{ cuando(h) }}</p>
                </div>
              </li>
            </ol>
          </div>
        </section>

        <!-- Historial de la cita -->
        <section v-else class="tu-detalle-seccion" data-prueba="historial">
          <p v-if="cargandoHistorial" class="pc-suave text-sm">
            {{ $t("detalleCita.historial.cargando") }}
          </p>
          <p
            v-else-if="errorHistorial"
            class="text-sm"
            :style="{ color: 'var(--error)' }"
          >
            {{ $t("detalleCita.historial.error") }}
          </p>
          <p
            v-else-if="(historial ?? []).length === 0"
            class="pc-suave text-sm"
          >
            {{ $t("detalleCita.historial.vacio") }}
          </p>
          <ol v-else class="pc-linea">
            <li v-for="(h, i) in historial" :key="i">
              <span class="pc-linea-punto" aria-hidden="true"></span>
              <div class="min-w-0">
                <p class="font-medium">{{ tituloHecho(h) }}</p>
                <p v-if="detalleHecho(h)" class="text-sm">
                  {{ detalleHecho(h) }}
                </p>
                <p class="pc-suave text-xs">{{ cuando(h) }}</p>
              </div>
            </li>
          </ol>
        </section>
      </template>
    </div>

    <template #pie>
      <div class="mr-auto flex flex-wrap gap-2">
        <button
          v-if="sePuedeMover"
          type="button"
          class="tu-btn tu-btn-fantasma"
          :aria-expanded="reprogramando"
          @click="abrirReprogramar"
        >
          <IconoNav nombre="agenda" :tam="18" />
          {{ $t("detalleCita.reprogramar") }}
        </button>
        <button
          v-if="sePuedeMover"
          type="button"
          class="tu-btn tu-btn-fantasma"
          style="color: var(--error)"
          :disabled="accionando"
          :aria-expanded="cancelando"
          @click="abrirCancelacion"
        >
          <IconoNav nombre="cerrar" :tam="18" />
          {{ $t("detalleCita.cancelar") }}
        </button>
      </div>
      <button
        type="button"
        class="tu-btn tu-btn-primario"
        @click="emit('cerrar')"
      >
        {{ $t("detalleCita.listo") }}
      </button>
    </template>
  </ModalDialogo>
</template>

<style scoped>
/* Sin `margin` en los hijos: el espacio lo pone el `gap` del contenedor. */
.pc {
  display: grid;
  gap: 1.4rem;
}
.pc-suave {
  color: var(--texto-suave);
}
.pc-cabeza {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1.1rem;
  align-items: start;
}
@media (min-width: 768px) {
  .pc-cabeza {
    grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr) auto;
  }
  .pc-cuando {
    padding-left: 1.25rem;
    border-left: 1px solid var(--borde);
  }
}
.pc-persona {
  display: flex;
  align-items: center;
  gap: 1rem;
}
.pc-nombre {
  font-size: 1.3rem;
  font-weight: 600;
  line-height: 1.25;
}
.pc-enlace {
  display: inline-block;
  margin-top: 0.15rem;
  color: var(--primario);
  font-size: 0.9rem;
  font-weight: 500;
}
.pc-enlace:hover {
  text-decoration: underline;
}
.pc-cuando {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  min-height: 3rem;
}
.pc-estados {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
@media (min-width: 768px) {
  .pc-estados {
    flex-direction: column;
    align-items: flex-end;
  }
}
.pc-punto {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 999px;
  background: var(--tono);
}
.pc-opciones {
  display: grid;
  gap: 0.7rem;
}
@media (min-width: 640px) {
  .pc-opciones {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
.pc-opcion {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.8rem;
  background: var(--superficie);
  text-align: left;
  transition:
    border-color 0.15s,
    background 0.15s;
}
.pc-opcion:not(:disabled):hover {
  border-color: var(--primario);
}
.pc-opcion:disabled {
  cursor: default;
}
.pc-opcion:disabled:not([aria-pressed="true"]) {
  opacity: 0.55;
}
.pc-opcion-icono {
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.4rem;
  height: 2.4rem;
  border-radius: 999px;
  background: var(--superficie-2);
  color: var(--texto-suave);
}
.pc-opcion-titulo {
  display: block;
  font-weight: 600;
}
.pc-opcion-sub {
  display: block;
  color: var(--texto-suave);
  font-size: 0.85rem;
}
.pc-opcion-bien {
  --tono: var(--exito);
}
.pc-opcion-mal {
  --tono: var(--error);
}
.pc-opcion-bien,
.pc-opcion-mal {
  border-color: var(--tono);
}
.pc-opcion-bien .pc-opcion-icono,
.pc-opcion-mal .pc-opcion-icono {
  background: color-mix(in srgb, var(--tono) 10%, var(--superficie));
  color: var(--tono);
}
.pc-opcion-bien .pc-opcion-titulo,
.pc-opcion-bien .pc-opcion-sub,
.pc-opcion-mal .pc-opcion-titulo,
.pc-opcion-mal .pc-opcion-sub {
  color: var(--tono);
}
.pc-notas {
  display: grid;
  grid-template-columns: 8rem minmax(0, 1fr);
  gap: 0.5rem 0.75rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.8rem;
  font-size: 0.9rem;
}
.pc-notas dt {
  color: var(--texto-suave);
}
.pc-cobro {
  display: grid;
  gap: 1rem;
  padding: 1.1rem 1.25rem;
  border: 1px solid var(--borde);
  border-radius: 0.85rem;
}
@media (min-width: 768px) {
  .pc-cobro {
    grid-template-columns: auto minmax(0, 1fr) auto auto;
    align-items: center;
  }
  .pc-cobro > div + div:not(.pc-cobrar) {
    padding-left: 1.25rem;
    border-left: 1px solid var(--borde);
  }
}
.pc-cobro > div {
  display: grid;
  justify-items: start;
  gap: 0.3rem;
}
.pc-etiqueta {
  color: var(--texto-suave);
  font-size: 0.85rem;
}
.pc-total {
  font-size: 1.6rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.pc-estado-pago {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  color: var(--tono);
  font-weight: 500;
}
.pc-cobrar {
  justify-items: stretch;
  white-space: nowrap;
}
.pc-movimientos {
  display: grid;
  gap: 0.6rem;
}
.pc-movimientos h4 {
  font-weight: 600;
}
.pc-linea {
  display: grid;
  gap: 0.9rem;
}
.pc-linea > li {
  position: relative;
  display: flex;
  gap: 0.8rem;
}
/* La línea une cada punto con el siguiente. */
.pc-linea > li:not(:last-child)::after {
  content: "";
  position: absolute;
  top: 1.25rem;
  bottom: -0.75rem;
  left: 0.27rem;
  border-left: 1px solid var(--borde);
}
.pc-linea-punto {
  flex-shrink: 0;
  width: 0.6rem;
  height: 0.6rem;
  margin-top: 0.45rem;
  border-radius: 999px;
  background: var(--primario);
}
</style>
