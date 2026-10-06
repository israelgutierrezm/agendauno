<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import CortePlanes from "@/components/CortePlanes.vue";
import IconoNav from "@/components/IconoNav.vue";
import MenuFlotante from "@/components/MenuFlotante.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import RegistrarPagoOrden, {
  type OrdenPorCobrar,
} from "@/components/RegistrarPagoOrden.vue";
import { puedeEntrar } from "@/lib/acceso";
import { fechaLocal } from "@/lib/agenda";
import { edadDe, fechaNacimientoTexto } from "@/lib/datosPersonales";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * El detalle de un cliente desde Miembros, en un modal (sin salir de la lista): su
 * plan y saldo, su última y próxima visita, sus datos, lo que hizo en el periodo y
 * las notas del equipo; y en pestañas, sus planes, su historial, sus pagos y todas
 * las notas. Desde aquí se cobra, se edita o se abre su ficha completa.
 */
export interface PersonaListado {
  id: string;
  nombre: string;
  segundo_nombre: string | null;
  primer_apellido: string | null;
  segundo_apellido: string | null;
  nombre_completo: string;
  email: string | null;
  celular?: string | null;
  fecha_nacimiento?: string | null;
  genero?: string | null;
  activo: boolean;
  archivado: boolean;
  alta?: string | null;
  sucursal?: { id: string; nombre: string } | null;
}
interface Resumen {
  ilimitado?: boolean;
  saldo_creditos: number;
  saldo_unidades: number;
  como_nos_conocio?: string | null;
  whatsapp?: { disponible: boolean; acepta: boolean; con_celular: boolean };
  membresia: {
    estado: string;
    valido_hasta: string | null;
    pausada_hasta?: string | null;
    plan?: string | null;
  };
  proxima_reserva: {
    clase: string | null;
    inicia_en: string;
    zona_horaria: string | null;
  } | null;
  ultima_visita: {
    clase: string | null;
    profesional: string | null;
    inicia_en: string | null;
    zona_horaria: string | null;
  } | null;
  estadisticas?: {
    dias: number;
    asistencias: number;
    reservadas: number;
    canceladas: number;
    no_asistio: number;
  };
  alertas: string[];
}
interface Nota {
  id: string;
  texto: string;
  autor: string | null;
  creada_en: string | null;
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
  instructor?: string | null;
}
interface Orden {
  id: string;
  fecha: string | null;
  estado: string;
  total_minor: number;
  moneda: string;
  concepto?: string | null;
}
interface Ficha {
  reservas: Reserva[];
  ordenes: Orden[] | null;
  pendientes?: Orden[] | null;
}
type Pestana = "resumen" | "planes" | "historial" | "pagos" | "notas";

const props = defineProps<{
  abierto: boolean;
  persona: PersonaListado | null;
}>();
const emit = defineEmits<{
  cerrar: [];
  editar: [];
  cobrar: [];
  baja: [];
}>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const puedeVender = computed(
  () => sesion.puede("ordenes.gestionar") && sesion.puede("productos.ver"),
);
const puedeEditar = computed(() => sesion.puede("miembros.gestionar"));
const puedeDarDeBaja = computed(() => sesion.puede("miembros.eliminar"));
const puedeVerPlanes = computed(() => sesion.puede("derechos.ver"));
const puedeEntrada = computed(() => sesion.puede("checkins.registrar"));
const puedeCobrarPendiente = computed(() => sesion.puede("ordenes.gestionar"));

const pestana = ref<Pestana>("resumen");
const resumen = ref<Resumen | null>(null);
const cargando = ref(false);
const error = ref<string | null>(null);
const dias = ref(30);
const notas = ref<Nota[]>([]);
const ficha = ref<Ficha | null>(null);
const cargandoFicha = ref(false);

const nombre = computed(
  () =>
    props.persona?.nombre_completo ||
    [props.persona?.nombre, props.persona?.primer_apellido]
      .filter(Boolean)
      .join(" "),
);
const apellidos = computed(
  () =>
    [props.persona?.primer_apellido, props.persona?.segundo_apellido]
      .filter(Boolean)
      .join(" ") || "—",
);
const estado = computed<{ texto: string; tono: string }>(() => {
  const p = props.persona;
  if (p?.archivado) {
    return {
      texto: t("detalleMiembro.estado.archivado"),
      tono: "var(--texto-suave)",
    };
  }
  return p?.activo
    ? { texto: t("detalleMiembro.estado.activo"), tono: "var(--exito)" }
    : { texto: t("detalleMiembro.estado.suspendido"), tono: "var(--aviso)" };
});
// En citas, los planes son opcionales: sin plan no se habla de plan ni de saldo.
const conPlanes = computed(
  () =>
    !sesion.esCitas ||
    Boolean(resumen.value?.membresia.plan) ||
    Boolean(resumen.value?.ilimitado) ||
    (resumen.value?.saldo_creditos ?? 0) > 0,
);
const visitas = computed(() => plural(sesion.terminologia.sesion));
const pestanas = computed<{ clave: Pestana; texto: string }[]>(() => [
  { clave: "resumen" as const, texto: t("detalleMiembro.pestanas.resumen") },
  ...(puedeVerPlanes.value && conPlanes.value
    ? [{ clave: "planes" as const, texto: t("detalleMiembro.pestanas.planes") }]
    : []),
  { clave: "historial" as const, texto: visitas.value },
  ...(ficha.value?.ordenes !== null && sesion.puede("ordenes.ver")
    ? [{ clave: "pagos" as const, texto: t("detalleMiembro.pestanas.pagos") }]
    : []),
  { clave: "notas" as const, texto: t("detalleMiembro.pestanas.notas") },
]);

function fecha(ymd: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(`${ymd.slice(0, 10)}T12:00:00`));
}
function fechaHora(iso: string | null, zona: string | null): string {
  if (!iso) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona ?? undefined,
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}
function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

const planTexto = computed(() => {
  const m = resumen.value?.membresia;
  if (!m || !m.plan) {
    return null;
  }
  if (m.estado === "pausada" && m.pausada_hasta) {
    return t("detalleMiembro.plan.pausa", { fecha: fecha(m.pausada_hasta) });
  }
  if (!m.valido_hasta) {
    return null;
  }
  return m.estado === "vencida"
    ? t("detalleMiembro.plan.vencio", { fecha: fecha(m.valido_hasta) })
    : t("detalleMiembro.plan.vence", { fecha: fecha(m.valido_hasta) });
});
const saldoTexto = computed(() => {
  const r = resumen.value;
  if (!r) {
    return "—";
  }
  if (r.ilimitado) {
    return t("detalleMiembro.saldo.ilimitado");
  }
  return t(
    "detalleMiembro.saldo.disponibles",
    { n: r.saldo_creditos },
    r.saldo_creditos === 0 ? 0 : r.saldo_creditos === 1 ? 1 : 2,
  );
});
// «Ver en agenda» abre la agenda en el día de su próxima reserva.
const diaProxima = computed(() => {
  const p = resumen.value?.proxima_reserva;
  return p
    ? fechaLocal(p.inicia_en, p.zona_horaria ?? "America/Mexico_City")
    : null;
});

async function cargarResumen(): Promise<void> {
  if (!props.persona) {
    return;
  }
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Resumen }>(
      `${base.value}/miembros/${props.persona.id}/resumen`,
      { params: { dias: dias.value } },
    );
    resumen.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}
async function cargarNotas(): Promise<void> {
  if (!props.persona) {
    return;
  }
  try {
    const { data } = await api.get<{ data: Nota[] }>(
      `${base.value}/miembros/${props.persona.id}/notas`,
    );
    notas.value = data.data;
  } catch {
    notas.value = [];
  }
}
async function cargarFicha(): Promise<void> {
  if (!props.persona || cargandoFicha.value) {
    return;
  }
  cargandoFicha.value = true;
  try {
    const { data } = await api.get<{ data: Ficha }>(
      `${base.value}/miembros/${props.persona.id}/ficha`,
    );
    ficha.value = data.data;
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    cargandoFicha.value = false;
  }
}

// Una nota nueva (en el resumen o en la pestaña de notas).
const escribiendo = ref(false);
const textoNota = ref("");
const guardandoNota = ref(false);

// Cada vez que se abre con otra persona, desde el resumen y con lo suyo.
watch(
  () => [props.abierto, props.persona?.id] as const,
  ([abierto]) => {
    if (!abierto || !props.persona) {
      return;
    }
    pestana.value = "resumen";
    resumen.value = null;
    ficha.value = null;
    notas.value = [];
    escribiendo.value = false;
    void cargarResumen();
    void cargarNotas();
    // Su historial y sus pagos (y saber si puede ver pagos) vienen de su ficha.
    void cargarFicha();
  },
  { immediate: true },
);
watch(dias, () => void cargarResumen());

// ---- Notas ----
async function guardarNota(): Promise<void> {
  if (!props.persona || textoNota.value.trim() === "") {
    return;
  }
  guardandoNota.value = true;
  try {
    const { data } = await api.post<{ data: Nota }>(
      `${base.value}/miembros/${props.persona.id}/notas`,
      { texto: textoNota.value.trim() },
    );
    notas.value = [data.data, ...notas.value];
    textoNota.value = "";
    escribiendo.value = false;
    toast.exito(t("detalleMiembro.notas.guardada"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardandoNota.value = false;
  }
}
async function eliminarNota(n: Nota): Promise<void> {
  if (
    !props.persona ||
    !(await confirmar(t("detalleMiembro.notas.confirmarEliminar"), {
      peligro: true,
    }))
  ) {
    return;
  }
  try {
    await api.delete(
      `${base.value}/miembros/${props.persona.id}/notas/${n.id}`,
    );
    notas.value = notas.value.filter((x) => x.id !== n.id);
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}
// Un momento (ISO) como fecha local de quien lo ve.
function fechaDe(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        day: "numeric",
        month: "short",
        year: "numeric",
      }).format(new Date(iso))
    : "";
}

// ---- Más acciones ----
const menu = ref(false);
const botonMenu = ref<HTMLElement | null>(null);
async function registrarEntrada(): Promise<void> {
  menu.value = false;
  if (!props.persona) {
    return;
  }
  try {
    const { data } = await api.post<{
      data: { permitido: boolean; codigo: string };
    }>(`${base.value}/accesos`, {
      persona_id: props.persona.id,
      metodo: "manual",
      sucursal_id: null,
    });
    const texto = `${
      data.data.permitido
        ? t("accesoRecepcion.permitido")
        : t("accesoRecepcion.denegado")
    } · ${t(`accesoRecepcion.codigos.${data.data.codigo}`)}`;
    if (data.data.permitido) {
      toast.exito(texto);
    } else {
      toast.error(texto);
    }
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

// ---- Cobrar lo que debe ----
const cobrando = ref<OrdenPorCobrar | null>(null);
function registrarPago(o: Orden): void {
  cobrando.value = {
    id: o.id,
    persona: nombre.value,
    concepto: o.concepto ?? null,
    total_minor: o.total_minor,
    moneda: o.moneda,
  };
}
function alRegistrar(): void {
  cobrando.value = null;
  ficha.value = null;
  void cargarFicha();
  void cargarResumen();
}

// Ámbar para lo que está por vencer o en pausa; rojo para el resto.
function colorAlerta(codigo: string): string {
  return codigo === "membresia_por_vencer" || codigo === "membresia_pausada"
    ? "var(--aviso)"
    : "var(--error)";
}
</script>

<template>
  <PanelLateral :abierto="abierto" :titulo="nombre" @cerrar="emit('cerrar')">
    <template #cabecera>
      <div v-if="persona" class="dm-cabecera" data-prueba="detalle-miembro">
        <AvatarIniciales :nombre="persona.nombre" tam="lg" />
        <!-- Nombre y estado juntos (en pantallas angostas, el estado baja). -->
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <h2 class="dm-nombre">{{ nombre }}</h2>
            <span class="tu-pildora" :style="{ '--tono': estado.tono }">{{
              estado.texto
            }}</span>
          </div>
          <p v-if="persona.email || persona.celular" class="dm-contacto">
            {{ persona.email ?? persona.celular }}
          </p>
        </div>
        <button
          ref="botonMenu"
          type="button"
          class="tu-icono-btn shrink-0"
          aria-haspopup="menu"
          :aria-expanded="menu"
          :aria-label="$t('detalleMiembro.acciones.menu')"
          data-prueba="menu-miembro"
          @click="menu = !menu"
        >
          <IconoNav nombre="puntos" :tam="20" />
        </button>
        <MenuFlotante :abierto="menu" :ancla="botonMenu" @cerrar="menu = false">
          <button
            v-if="puedeEditar"
            type="button"
            class="dm-menu"
            role="menuitem"
            @click="
              menu = false;
              emit('editar');
            "
          >
            {{ $t("detalleMiembro.acciones.editar") }}
          </button>
          <RouterLink
            :to="{
              name: 'ficha-miembro',
              params: { id: persona.id },
              query: { seccion: 'expediente' },
            }"
            class="dm-menu"
            role="menuitem"
            >{{ $t("detalleMiembro.acciones.expediente") }}</RouterLink
          >
          <button
            v-if="puedeEntrada"
            type="button"
            class="dm-menu"
            role="menuitem"
            @click="registrarEntrada"
          >
            {{ $t("detalleMiembro.acciones.entrada") }}
          </button>
          <!-- En pantallas angostas, darle de baja vive aquí (el pie, para lo diario). -->
          <button
            v-if="puedeDarDeBaja"
            type="button"
            class="dm-menu sm:hidden"
            role="menuitem"
            style="color: var(--error)"
            @click="
              menu = false;
              emit('baja');
            "
          >
            {{ $t("detalleMiembro.acciones.baja") }}
          </button>
        </MenuFlotante>
      </div>
    </template>

    <template v-if="persona">
      <div class="tu-pestanas" role="group" data-prueba="pestanas-miembro">
        <button
          v-for="p in pestanas"
          :key="p.clave"
          type="button"
          :aria-pressed="pestana === p.clave"
          @click="pestana = p.clave"
        >
          {{ p.texto }}
        </button>
      </div>

      <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
        {{ error }}
        <button type="button" class="tu-enlace ml-2" @click="cargarResumen">
          {{ $t("comun.reintentar") }}
        </button>
      </p>
      <p
        v-else-if="cargando && !resumen"
        class="mt-4 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>

      <!-- RESUMEN -->
      <div v-if="pestana === 'resumen' && resumen" class="dm-resumen">
        <!-- Lo que pide atención, en una línea -->
        <p
          v-if="resumen.alertas.length > 0"
          class="flex flex-wrap gap-x-3 gap-y-1 text-sm"
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

        <div class="dm-rejilla">
          <section
            v-if="conPlanes"
            class="dm-tarjeta"
            data-prueba="plan-vigente"
          >
            <h3 class="dm-titulo">{{ $t("detalleMiembro.plan.titulo") }}</h3>
            <div class="dm-dato">
              <span class="dm-icono" aria-hidden="true"
                ><IconoNav nombre="etiqueta" :tam="22"
              /></span>
              <div class="min-w-0">
                <p class="dm-valor">
                  {{
                    resumen.membresia.plan ?? $t("detalleMiembro.plan.sinPlan")
                  }}
                </p>
                <p v-if="planTexto" class="dm-suave">{{ planTexto }}</p>
              </div>
            </div>
            <button
              v-if="puedeVerPlanes"
              type="button"
              class="tu-btn tu-btn-fantasma text-sm dm-boton"
              @click="pestana = 'planes'"
            >
              {{ $t("detalleMiembro.plan.verDetalle") }}
            </button>
          </section>

          <section v-if="conPlanes" class="dm-tarjeta" data-prueba="saldo">
            <h3 class="dm-titulo">{{ $t("detalleMiembro.saldo.titulo") }}</h3>
            <div class="dm-dato">
              <span class="dm-icono" aria-hidden="true"
                ><IconoNav nombre="dinero" :tam="22"
              /></span>
              <div class="min-w-0">
                <p class="dm-valor">{{ saldoTexto }}</p>
                <p class="dm-suave">
                  {{
                    resumen.ilimitado
                      ? $t("detalleMiembro.saldo.ilimitadoAyuda")
                      : $t("detalleMiembro.saldo.ayuda")
                  }}
                </p>
              </div>
            </div>
            <button
              v-if="puedeVerPlanes"
              type="button"
              class="tu-btn tu-btn-fantasma text-sm dm-boton"
              @click="pestana = 'planes'"
            >
              {{ $t("detalleMiembro.saldo.historial") }}
            </button>
          </section>

          <section class="dm-tarjeta" data-prueba="ultima-visita">
            <h3 class="dm-titulo">{{ $t("detalleMiembro.ultima.titulo") }}</h3>
            <div class="dm-dato">
              <span class="dm-icono" aria-hidden="true"
                ><IconoNav nombre="agenda" :tam="22"
              /></span>
              <div class="min-w-0">
                <template v-if="resumen.ultima_visita">
                  <p class="dm-valor first-letter:uppercase">
                    {{
                      fechaHora(
                        resumen.ultima_visita.inicia_en,
                        resumen.ultima_visita.zona_horaria,
                      )
                    }}
                  </p>
                  <p class="dm-suave">
                    {{
                      [
                        resumen.ultima_visita.clase,
                        resumen.ultima_visita.profesional,
                      ]
                        .filter(Boolean)
                        .join(" · ")
                    }}
                  </p>
                </template>
                <p v-else class="dm-suave">
                  {{ $t("detalleMiembro.ultima.sin") }}
                </p>
              </div>
            </div>
          </section>

          <section class="dm-tarjeta" data-prueba="proxima-reserva">
            <h3 class="dm-titulo">{{ $t("detalleMiembro.proxima.titulo") }}</h3>
            <div class="dm-dato">
              <span class="dm-icono" aria-hidden="true"
                ><IconoNav nombre="agenda" :tam="22"
              /></span>
              <div class="min-w-0">
                <template v-if="resumen.proxima_reserva">
                  <p class="dm-valor first-letter:uppercase">
                    {{
                      fechaHora(
                        resumen.proxima_reserva.inicia_en,
                        resumen.proxima_reserva.zona_horaria,
                      )
                    }}
                  </p>
                  <p class="dm-suave">
                    {{ resumen.proxima_reserva.clase ?? "—" }}
                  </p>
                </template>
                <p v-else class="dm-suave">
                  {{ $t("detalleMiembro.proxima.sin") }}
                </p>
              </div>
            </div>
            <RouterLink
              v-if="diaProxima && puedeEntrar('agenda', sesion)"
              :to="{ name: 'agenda', query: { fecha: diaProxima } }"
              class="tu-btn tu-btn-fantasma text-sm dm-boton"
              >{{ $t("detalleMiembro.proxima.verAgenda") }}</RouterLink
            >
          </section>
        </div>

        <!-- Información personal -->
        <section class="dm-tarjeta" data-prueba="info-personal">
          <div class="dm-encabezado">
            <h3 class="dm-titulo">{{ $t("detalleMiembro.info.titulo") }}</h3>
            <button
              v-if="puedeEditar"
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              @click="emit('editar')"
            >
              {{ $t("detalleMiembro.info.editar") }}
            </button>
          </div>
          <dl class="dm-datos">
            <div>
              <dt>{{ $t("detalleMiembro.info.nombre") }}</dt>
              <dd>
                {{
                  [persona.nombre, persona.segundo_nombre]
                    .filter(Boolean)
                    .join(" ")
                }}
              </dd>
            </div>
            <div>
              <dt>{{ $t("detalleMiembro.info.apellidos") }}</dt>
              <dd>{{ apellidos }}</dd>
            </div>
            <div>
              <dt>{{ $t("detalleMiembro.info.correo") }}</dt>
              <dd class="break-all">{{ persona.email ?? "—" }}</dd>
            </div>
            <div>
              <dt>{{ $t("detalleMiembro.info.celular") }}</dt>
              <dd>{{ persona.celular ?? "—" }}</dd>
            </div>
            <div data-prueba="info-nacimiento">
              <dt>{{ $t("datosPersonales.fechaNacimiento") }}</dt>
              <dd v-if="persona.fecha_nacimiento">
                {{ fechaNacimientoTexto(persona.fecha_nacimiento) }}
                <span :style="{ color: 'var(--texto-suave)' }"
                  >·
                  {{
                    $t("datosPersonales.edad", {
                      n: edadDe(persona.fecha_nacimiento),
                    })
                  }}</span
                >
              </dd>
              <dd v-else>—</dd>
            </div>
            <div>
              <dt>{{ $t("datosPersonales.genero") }}</dt>
              <dd>
                {{
                  persona.genero
                    ? $t(`datosPersonales.generos.${persona.genero}`)
                    : "—"
                }}
              </dd>
            </div>
            <div v-if="persona.sucursal">
              <dt>{{ $t("detalleMiembro.info.sucursal") }}</dt>
              <dd>{{ persona.sucursal.nombre }}</dd>
            </div>
            <div v-if="persona.alta">
              <dt>{{ $t("detalleMiembro.info.alta") }}</dt>
              <dd>{{ fecha(persona.alta) }}</dd>
            </div>
            <div v-if="resumen.como_nos_conocio">
              <dt>{{ $t("detalleMiembro.info.origen") }}</dt>
              <dd>
                {{ $t(`perfilPublico.origenes.${resumen.como_nos_conocio}`) }}
              </dd>
            </div>
            <div v-if="resumen.whatsapp?.disponible">
              <dt>{{ $t("detalleMiembro.info.whatsapp") }}</dt>
              <dd>
                {{
                  resumen.whatsapp.acepta
                    ? $t("detalleMiembro.info.acepta")
                    : $t("detalleMiembro.info.noAcepta")
                }}
              </dd>
            </div>
          </dl>
        </section>

        <!-- Estadísticas del periodo -->
        <section
          v-if="resumen.estadisticas"
          class="dm-tarjeta"
          data-prueba="estadisticas"
        >
          <div class="dm-encabezado">
            <h3 class="dm-titulo">
              {{ $t("detalleMiembro.estadisticas.titulo") }}
            </h3>
            <select
              v-model.number="dias"
              class="tu-input w-auto text-sm"
              :aria-label="$t('detalleMiembro.estadisticas.periodo')"
              data-prueba="periodo-estadisticas"
            >
              <option :value="30">
                {{ $t("detalleMiembro.estadisticas.dias30") }}
              </option>
              <option :value="90">
                {{ $t("detalleMiembro.estadisticas.dias90") }}
              </option>
              <option :value="365">
                {{ $t("detalleMiembro.estadisticas.dias365") }}
              </option>
            </select>
          </div>
          <dl class="dm-estadisticas">
            <div>
              <dt>{{ $t("detalleMiembro.estadisticas.asistencias") }}</dt>
              <dd>{{ resumen.estadisticas.asistencias }}</dd>
            </div>
            <div>
              <dt>{{ $t("detalleMiembro.estadisticas.reservadas") }}</dt>
              <dd>{{ resumen.estadisticas.reservadas }}</dd>
            </div>
            <div>
              <dt>{{ $t("detalleMiembro.estadisticas.canceladas") }}</dt>
              <dd>{{ resumen.estadisticas.canceladas }}</dd>
            </div>
            <div>
              <dt>{{ $t("detalleMiembro.estadisticas.noAsistio") }}</dt>
              <dd
                :style="
                  resumen.estadisticas.no_asistio > 0
                    ? { color: 'var(--aviso)' }
                    : undefined
                "
              >
                {{ resumen.estadisticas.no_asistio }}
              </dd>
            </div>
          </dl>
        </section>

        <!-- Notas: las más recientes -->
        <section class="dm-tarjeta" data-prueba="notas-resumen">
          <div class="dm-encabezado">
            <h3 class="dm-titulo">{{ $t("detalleMiembro.notas.titulo") }}</h3>
            <button
              v-if="puedeEditar && !escribiendo"
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              @click="escribiendo = true"
            >
              <IconoNav nombre="mas" :tam="16" />
              {{ $t("detalleMiembro.notas.agregar") }}
            </button>
          </div>
          <form
            v-if="escribiendo"
            class="mt-3 space-y-2"
            @submit.prevent="guardarNota"
          >
            <textarea
              v-model="textoNota"
              class="tu-input"
              rows="2"
              maxlength="2000"
              :placeholder="$t('detalleMiembro.notas.placeholder')"
              data-prueba="texto-nota"
            />
            <div class="flex justify-end gap-2">
              <button
                type="button"
                class="tu-btn tu-btn-fantasma text-sm"
                @click="escribiendo = false"
              >
                {{ $t("detalleMiembro.notas.cancelar") }}
              </button>
              <button
                type="submit"
                class="tu-btn tu-btn-primario text-sm"
                :disabled="guardandoNota || textoNota.trim() === ''"
              >
                {{ $t("detalleMiembro.notas.guardar") }}
              </button>
            </div>
          </form>
          <p
            v-if="notas.length === 0 && !escribiendo"
            class="mt-2 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("detalleMiembro.notas.vacio") }}
          </p>
          <ul v-else class="dm-notas">
            <li v-for="n in notas.slice(0, 2)" :key="n.id" class="dm-nota">
              <p class="whitespace-pre-line">{{ n.texto }}</p>
              <p class="dm-suave text-xs">
                {{
                  [fechaDe(n.creada_en), n.autor].filter(Boolean).join(" · ")
                }}
              </p>
            </li>
          </ul>
          <button
            v-if="notas.length > 2"
            type="button"
            class="tu-enlace mt-2 text-sm"
            @click="pestana = 'notas'"
          >
            {{ $t("detalleMiembro.notas.todas") }}
          </button>
        </section>
      </div>

      <!-- PLANES Y CRÉDITOS -->
      <CortePlanes
        v-else-if="pestana === 'planes'"
        class="mt-4"
        equipo
        :url="`${base}/miembros/${persona.id}/planes`"
      />

      <!-- HISTORIAL (clases o citas) -->
      <div v-else-if="pestana === 'historial'" class="mt-4">
        <p
          v-if="!ficha"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("detalleMiembro.historial.cargando") }}
        </p>
        <p
          v-else-if="ficha.reservas.length === 0"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("detalleMiembro.historial.vacio") }}
        </p>
        <ul v-else class="dm-lista" data-prueba="historial-miembro">
          <li v-for="r in ficha.reservas" :key="r.id" class="dm-fila">
            <div class="min-w-0">
              <p class="truncate font-medium">{{ r.clase ?? "—" }}</p>
              <p class="dm-suave text-xs first-letter:uppercase">
                {{
                  [fechaHora(r.inicia_en, r.zona_horaria), r.instructor]
                    .filter(Boolean)
                    .join(" · ")
                }}
              </p>
            </div>
            <span
              class="tu-pildora shrink-0"
              :style="{
                '--tono':
                  r.asistencia === 'presente'
                    ? 'var(--exito)'
                    : r.asistencia === 'ausente'
                      ? 'var(--aviso)'
                      : 'var(--texto-suave)',
              }"
              >{{
                r.retardo && r.asistencia === "presente"
                  ? $t("agenda.roster.retardo")
                  : $t(`agenda.roster.${r.asistencia ?? r.estado}`)
              }}</span
            >
          </li>
        </ul>
      </div>

      <!-- PAGOS -->
      <div v-else-if="pestana === 'pagos' && ficha" class="mt-4 space-y-5">
        <section>
          <h3 class="dm-titulo">{{ $t("detalleMiembro.pagos.pendientes") }}</h3>
          <p
            v-if="(ficha.pendientes ?? []).length === 0"
            class="mt-2 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("detalleMiembro.pagos.alCorriente") }}
          </p>
          <ul v-else class="dm-lista" data-prueba="pendientes-miembro">
            <li v-for="o in ficha.pendientes ?? []" :key="o.id" class="dm-fila">
              <div class="min-w-0">
                <p class="truncate font-medium">{{ o.concepto ?? "—" }}</p>
                <p class="dm-suave text-xs">
                  {{ fechaDe(o.fecha) }}
                </p>
              </div>
              <span class="tabular-nums">{{
                dinero(o.total_minor, o.moneda)
              }}</span>
              <button
                v-if="puedeCobrarPendiente"
                type="button"
                class="tu-btn tu-btn-fantasma text-sm"
                @click="registrarPago(o)"
              >
                {{ $t("detalleMiembro.pagos.registrar") }}
              </button>
            </li>
          </ul>
        </section>
        <section>
          <h3 class="dm-titulo">{{ $t("detalleMiembro.pagos.compras") }}</h3>
          <p
            v-if="(ficha.ordenes ?? []).length === 0"
            class="mt-2 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("detalleMiembro.pagos.vacio") }}
          </p>
          <ul v-else class="dm-lista">
            <li v-for="o in ficha.ordenes ?? []" :key="o.id" class="dm-fila">
              <div class="min-w-0">
                <p class="truncate font-medium">{{ o.concepto ?? "—" }}</p>
                <p class="dm-suave text-xs">
                  {{ fechaDe(o.fecha) }} ·
                  {{ $t(`ficha.ordenes.estados.${o.estado}`) }}
                </p>
              </div>
              <span class="tabular-nums">{{
                dinero(o.total_minor, o.moneda)
              }}</span>
            </li>
          </ul>
        </section>
      </div>

      <!-- NOTAS -->
      <div v-else-if="pestana === 'notas'" class="mt-4">
        <p class="dm-suave text-sm">{{ $t("detalleMiembro.notas.ayuda") }}</p>
        <form
          v-if="puedeEditar"
          class="mt-3 space-y-2"
          @submit.prevent="guardarNota"
        >
          <textarea
            v-model="textoNota"
            class="tu-input"
            rows="2"
            maxlength="2000"
            :placeholder="$t('detalleMiembro.notas.placeholder')"
          />
          <div class="flex justify-end">
            <button
              type="submit"
              class="tu-btn tu-btn-primario text-sm"
              :disabled="guardandoNota || textoNota.trim() === ''"
            >
              {{ $t("detalleMiembro.notas.guardar") }}
            </button>
          </div>
        </form>
        <p
          v-if="notas.length === 0"
          class="mt-4 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("detalleMiembro.notas.vacio") }}
        </p>
        <ul v-else class="dm-notas" data-prueba="notas-miembro">
          <li v-for="n in notas" :key="n.id" class="dm-nota">
            <div class="flex items-start justify-between gap-3">
              <p class="min-w-0 whitespace-pre-line">{{ n.texto }}</p>
              <button
                v-if="puedeEditar"
                type="button"
                class="tu-enlace shrink-0 text-xs"
                style="color: var(--error)"
                @click="eliminarNota(n)"
              >
                {{ $t("detalleMiembro.notas.eliminar") }}
              </button>
            </div>
            <p class="dm-suave text-xs">
              {{ [fechaDe(n.creada_en), n.autor].filter(Boolean).join(" · ") }}
            </p>
          </li>
        </ul>
      </div>
    </template>

    <template #pie>
      <div v-if="persona" class="dm-pie">
        <button
          v-if="puedeDarDeBaja"
          type="button"
          class="tu-btn tu-btn-fantasma hidden text-sm sm:inline-flex"
          style="color: var(--error)"
          @click="emit('baja')"
        >
          {{ $t("detalleMiembro.acciones.baja") }}
        </button>
        <div class="dm-pie-acciones">
          <!-- En pantallas angostas basta la X de arriba. -->
          <button
            type="button"
            class="tu-btn tu-btn-fantasma hidden text-sm sm:inline-flex"
            @click="emit('cerrar')"
          >
            {{ $t("detalleMiembro.acciones.cerrar") }}
          </button>
          <RouterLink
            :to="{ name: 'ficha-miembro', params: { id: persona.id } }"
            class="tu-btn tu-btn-fantasma text-sm"
            data-prueba="ficha-completa"
            >{{ $t("detalleMiembro.acciones.fichaCompleta") }}</RouterLink
          >
          <button
            v-if="puedeVender"
            type="button"
            class="tu-btn tu-btn-primario text-sm"
            data-prueba="cobrar-miembro"
            @click="emit('cobrar')"
          >
            {{ $t("detalleMiembro.acciones.cobrar") }}
          </button>
        </div>
      </div>
    </template>
  </PanelLateral>

  <RegistrarPagoOrden
    :base="base"
    :orden="cobrando"
    @cerrar="cobrando = null"
    @registrado="alRegistrar"
  />
</template>

<style scoped>
.dm-cabecera {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  min-width: 0;
  flex: 1;
}
/* Se parte entre palabras; solo una palabra que no cabe se corta. */
.dm-nombre {
  min-width: 0;
  font-size: 1.15rem;
  font-weight: 600;
  letter-spacing: -0.015em;
  overflow-wrap: break-word;
}
.dm-contacto {
  margin-top: 0.15rem;
  overflow: hidden;
  font-size: 0.875rem;
  color: var(--texto-suave);
  text-overflow: ellipsis;
  white-space: nowrap;
}
.dm-menu {
  display: block;
  width: 100%;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  text-align: left;
  color: var(--texto);
  text-decoration: none;
}
.dm-menu:hover {
  background: var(--superficie-2);
}
.dm-resumen {
  display: grid;
  gap: 1rem;
  margin-top: 1rem;
}
.dm-rejilla {
  display: grid;
  gap: 1rem;
}
@media (min-width: 640px) {
  .dm-rejilla {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
.dm-tarjeta {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-width: 0;
  padding: 1rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
}
.dm-encabezado {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.dm-titulo {
  font-size: 0.9rem;
  font-weight: 500;
}
.dm-dato {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  min-width: 0;
}
/* El ícono sobre el tinte del acento; el trazo, del acento hacia el texto (se lee
   en todos los temas). */
.dm-icono {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.65rem;
  background: var(--primario-suave);
  color: color-mix(in srgb, var(--acento), var(--texto) 35%);
}
.dm-valor {
  font-size: 1.05rem;
  font-weight: 600;
  overflow-wrap: anywhere;
}
.dm-suave {
  color: var(--texto-suave);
  font-size: 0.85rem;
}
.dm-boton {
  align-self: flex-start;
  margin-top: auto;
}
.dm-datos {
  display: grid;
  gap: 0.85rem 1.5rem;
}
@media (min-width: 640px) {
  .dm-datos {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
.dm-datos dt {
  font-size: 0.8rem;
  color: var(--texto-suave);
}
.dm-datos dd {
  margin-top: 0.15rem;
  font-size: 0.925rem;
}
.dm-estadisticas {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.5rem;
}
@media (min-width: 640px) {
  .dm-estadisticas {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
.dm-estadisticas > div {
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: 0.6rem;
}
.dm-estadisticas dt {
  font-size: 0.8rem;
  color: var(--texto-suave);
}
.dm-estadisticas dd {
  margin-top: 0.2rem;
  font-size: 1.35rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.dm-notas {
  display: grid;
  gap: 0.5rem;
  margin-top: 0.75rem;
}
.dm-nota {
  display: grid;
  gap: 0.25rem;
  padding: 0.75rem;
  border-radius: 0.6rem;
  background: color-mix(in srgb, var(--fondo) 60%, var(--superficie));
  font-size: 0.9rem;
}
.dm-lista {
  margin-top: 0.5rem;
}
.dm-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  padding: 0.7rem 0;
  border-top: 1px solid var(--borde);
  font-size: 0.9rem;
}
.dm-fila:first-child {
  border-top: 0;
}
.dm-pie {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.dm-pie-acciones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-left: auto;
}
/* En pantallas angostas, la ficha y el cobro se reparten el ancho. */
@media (max-width: 639px) {
  .dm-pie-acciones {
    flex: 1;
  }
  .dm-pie-acciones > * {
    flex: 1 1 auto;
    justify-content: center;
    white-space: nowrap;
  }
}
</style>
