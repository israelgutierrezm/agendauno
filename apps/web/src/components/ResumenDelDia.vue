<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import ActualizadoHace from "@/components/ActualizadoHace.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import TarjetaPrincipal from "@/components/TarjetaPrincipal.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { puedeEntrar } from "@/lib/acceso";
import { aHora, fechaLocal, minutosLocal } from "@/lib/agenda";
import { useRecargarAlVolver } from "@/lib/alVolver";
import { api, mensajeDeError } from "@/lib/api";
import { lugarDelClima, useClima } from "@/lib/clima";
import { fotoNegocio } from "@/lib/fotoNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * El día de hoy en el Inicio del negocio (GET /inicio/hoy), con el estilo de los
 * Inicios del portal: la tarjeta grande con lo que sigue (o lo que está en curso),
 * los indicadores del día, la foto del giro y el clima del negocio; debajo la
 * agenda del día y lo pendiente (cobros y renovaciones). Cada bloque llega solo si
 * quien entra tiene el permiso de su pantalla.
 *
 * Cada tipo de negocio ve lo suyo (ADR 0091). Con citas: quién viene después, quién
 * llegó, qué falta por atender y por cobrar, y dónde hay espacios libres. Con clases:
 * qué clases hay, cuántos lugares están ocupados, qué listas faltan por registrar,
 * quién está en espera y qué planes están por vencer.
 */
interface SesionHoy {
  id: string;
  tipo: string;
  oferta: string | null;
  instructor: string | null;
  sucursal: string | null;
  cliente: string | null;
  inicia_en: string;
  zona_horaria: string;
  capacidad: number | null;
  esperados: number;
  llegaron: number;
  sin_marcar: number;
  por_cobrar?: boolean;
  en_espera?: number;
  cancelada: boolean;
  momento: "proxima" | "en_curso" | "termino" | "cancelada";
}
interface Libre {
  profesional: string;
  sucursal: string | null;
  zona_horaria: string | null;
  huecos: number;
  siguiente: string | null;
}
interface Hoy {
  fecha: string;
  modalidad?: "citas" | "clases";
  agenda: {
    totales: {
      sesiones: number;
      esperados: number;
      llegaron: number;
      sin_marcar: number;
      capacidad?: number;
      listas_pendientes?: number;
      en_espera?: number;
      por_atender?: number;
      por_cobrar?: number;
    };
    sesiones: SesionHoy[];
  } | null;
  libres?: Libre[] | null;
  cobros: {
    ordenes_pendientes: number;
    por_cobrar: { moneda: string; total_minor: number }[];
    en_mora: number;
  } | null;
  renovaciones: { por_vencer: number; vencidas: number; dias: number } | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const hoy = ref<Hoy | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
// Cuándo se trajo lo que se ve, y si se está volviendo a pedir sin quitarlo.
const actualizadoEn = ref<Date | null>(null);
const actualizando = ref(false);
const busqueda = ref("");
const filtro = ref<"todas" | "proximas" | "canceladas">("todas");
const agendaVisible = computed(() => {
  const q = busqueda.value
    .trim()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase();
  return (hoy.value?.agenda?.sesiones ?? []).filter((s) => {
    const coincideEstado =
      filtro.value === "todas" ||
      (filtro.value === "canceladas"
        ? s.cancelada
        : !s.cancelada &&
          (s.momento === "proxima" || s.momento === "en_curso"));
    const texto = [s.oferta, s.cliente, s.instructor, s.sucursal]
      .filter(Boolean)
      .join(" ")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase();
    return coincideEstado && (!q || texto.includes(q));
  });
});
function limpiar(): void {
  busqueda.value = "";
  filtro.value = "todas";
}
function ocupacion(s: SesionHoy): number {
  return s.capacidad && s.capacidad > 0
    ? Math.max(0, Math.min(100, (s.esperados / s.capacidad) * 100))
    : 0;
}

const zonaNavegador = Intl.DateTimeFormat().resolvedOptions().timeZone;

const foto = computed(() => fotoNegocio(sesion.estudio?.perfil));
const esCitas = computed(() => hoy.value?.modalidad === "citas");
// El clima del negocio (su sede) o el de la próxima clase de quien entra.
const { clima, cargar: cargarClima } = useClima(
  () => `/api/v1/app/${sesion.slug}/clima`,
);
const climaLugar = computed(() => lugarDelClima(clima.value, t));

// Lo que está en curso o lo siguiente de hoy (sin las canceladas).
const enCurso = computed(
  () =>
    hoy.value?.agenda?.sesiones.find((s) => s.momento === "en_curso") ?? null,
);
const siguiente = computed(
  () =>
    enCurso.value ??
    hoy.value?.agenda?.sesiones.find((s) => s.momento === "proxima") ??
    null,
);
const etiquetaDia = computed(() => {
  if (enCurso.value) {
    return t("operacion.hoy.enCurso");
  }
  if (!siguiente.value) {
    return t("operacion.hoy.titulo");
  }
  return esCitas.value
    ? t("operacion.hoy.citas.vieneDespues")
    : t("operacion.hoy.loQueSigue");
});
const tituloDia = computed(() => {
  const s = siguiente.value;
  if (s) {
    // En citas importa quién viene; el servicio va debajo.
    return esCitas.value && s.cliente ? s.cliente : nombre(s);
  }
  if ((hoy.value?.agenda?.totales.sesiones ?? 0) > 0) {
    return esCitas.value
      ? t("operacion.hoy.citas.diaTerminado")
      : t("operacion.hoy.diaTerminado");
  }
  return esCitas.value
    ? t("operacion.hoy.citas.sinCitas")
    : t("operacion.hoy.sinSesiones");
});
const detalleSiguiente = computed(() => {
  const s = siguiente.value;
  if (!s) {
    return "";
  }
  const partes = esCitas.value
    ? [s.oferta, s.instructor, s.sucursal]
    : [s.sucursal, s.instructor];
  return partes.filter(Boolean).join(" · ");
});

/**
 * Trae el día. En segundo plano (al volver a la pestaña, cada pocos minutos o con
 * «Actualizar») no quita lo que se ve mientras llega lo nuevo.
 */
async function cargar(enSegundoPlano = false): Promise<void> {
  if (enSegundoPlano && hoy.value !== null) {
    actualizando.value = true;
  } else {
    cargando.value = true;
  }
  error.value = null;
  try {
    const { data } = await api.get<{ data: Hoy }>(
      `/api/v1/app/${sesion.slug}/inicio/hoy`,
      {
        params: {
          fecha: fechaLocal(new Date().toISOString(), zonaNavegador),
        },
      },
    );
    hoy.value = data.data;
    actualizadoEn.value = new Date();
  } catch (e) {
    // En segundo plano, un fallo no borra lo que ya se ve.
    if (!enSegundoPlano || hoy.value === null) {
      error.value = mensajeDeError(e);
    }
  } finally {
    cargando.value = false;
    actualizando.value = false;
  }
}

// El negocio sigue operando: al volver a la pestaña y cada pocos minutos (si se está
// viendo), las cifras y los estados se ponen al día.
useRecargarAlVolver(() => cargar(true));
let periodico: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
  periodico = setInterval(() => {
    if (document.visibilityState === "visible") {
      void cargar(true);
    }
  }, 3 * 60_000);
});
onUnmounted(() => clearInterval(periodico));

function hora(s: SesionHoy): string {
  return aHora(minutosLocal(s.inicia_en, s.zona_horaria));
}

function nombre(s: SesionHoy): string {
  if (s.tipo === "cita" && s.cliente) {
    return `${s.oferta ?? ""} · ${s.cliente}`;
  }
  return s.oferta ?? "—";
}

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

const porCobrar = computed(() =>
  (hoy.value?.cobros?.por_cobrar ?? [])
    .map((m) => dinero(m.total_minor, m.moneda))
    .join(" + "),
);

// Los indicadores del día: cada tipo de negocio, sus preguntas (no el mismo
// indicador con otro nombre).
const indicadores = computed<Indicador[]>(() => {
  const tot = hoy.value?.agenda?.totales;
  if (!tot) {
    return [];
  }
  if (esCitas.value) {
    const porCobrar = tot.por_cobrar ?? 0;
    return [
      { clave: "citas", valor: String(tot.sesiones), icono: "agenda" },
      { clave: "llegaron", valor: String(tot.llegaron), icono: "hecho" },
      {
        clave: "porAtender",
        valor: String(tot.por_atender ?? 0),
        icono: "reloj",
      },
      {
        clave: "porCobrar",
        valor: String(porCobrar),
        icono: "dinero",
        aviso: porCobrar > 0,
      },
    ].map((i) => ({ ...i, etiqueta: t(`operacion.hoy.citas.kpi.${i.clave}`) }));
  }
  const capacidad = tot.capacidad ?? 0;
  const listas = tot.listas_pendientes ?? 0;
  const lista: Omit<Indicador, "etiqueta">[] = [
    { clave: "clases", valor: String(tot.sesiones), icono: "agenda" },
    {
      clave: "ocupados",
      valor:
        capacidad > 0
          ? t("operacion.hoy.cupo", { n: tot.esperados, total: capacidad })
          : String(tot.esperados),
      icono: "personas",
    },
    {
      clave: "listas",
      valor: String(listas),
      icono: "lista",
      aviso: listas > 0,
    },
    { clave: "enEspera", valor: String(tot.en_espera ?? 0), icono: "reloj" },
  ];
  const r = hoy.value?.renovaciones;
  if (r) {
    lista.push({
      clave: "porVencer",
      valor: String(r.por_vencer),
      icono: "pulso",
    });
  }
  return lista.map((i) => ({
    ...i,
    etiqueta: t(`operacion.hoy.clases.kpi.${i.clave}`),
  }));
});

// Espacios libres de hoy (citas): quién tiene huecos y desde qué hora.
function horaIso(iso: string, zona: string | null): string {
  return aHora(minutosLocal(iso, zona ?? zonaNavegador));
}
const libres = computed(() => hoy.value?.libres ?? null);

const hayPendientes = computed(() => {
  const c = hoy.value?.cobros;
  const r = hoy.value?.renovaciones;
  return (
    (c !== null &&
      c !== undefined &&
      (c.ordenes_pendientes > 0 || c.en_mora > 0)) ||
    (r !== null && r !== undefined && (r.por_vencer > 0 || r.vencidas > 0))
  );
});

onMounted(() => {
  void cargar();
  void cargarClima();
});
</script>

<template>
  <p v-if="cargando" class="mt-6" :style="{ color: 'var(--texto-suave)' }">
    {{ $t("comun.cargando") }}
  </p>
  <div v-else-if="error" class="mt-6 tu-card p-5" style="color: var(--error)">
    {{ error }}
    <button type="button" class="tu-enlace ml-2" @click="cargar()">
      {{ $t("comun.reintentar") }}
    </button>
  </div>

  <template v-else-if="hoy">
    <ActualizadoHace
      class="mt-4"
      :en="actualizadoEn"
      :actualizando="actualizando"
      @actualizar="cargar(true)"
    />
    <!-- La tarjeta principal: lo que sigue hoy y los indicadores del día -->
    <TarjetaPrincipal
      v-if="hoy.agenda"
      class="mt-2"
      :etiqueta="etiquetaDia"
      :etiqueta-viva="enCurso !== null"
      :foto="foto"
      :clima="clima"
      :clima-lugar="climaLugar"
      compacta
    >
      <p class="mt-2 text-2xl font-semibold sm:text-3xl">{{ tituloDia }}</p>
      <p v-if="siguiente" class="mt-2" data-prueba="detalle-siguiente">
        {{ hora(siguiente) }}
        <span v-if="detalleSiguiente" :style="{ color: 'var(--texto-suave)' }">
          · {{ detalleSiguiente }}</span
        >
      </p>
      <p
        v-else-if="hoy.agenda && hoy.agenda.totales.sesiones > 0"
        class="mt-2 text-sm"
        style="color: var(--texto-suave)"
      >
        {{
          $t(
            esCitas
              ? "operacion.hoy.citas.revisarCierre"
              : "operacion.hoy.clases.revisarCierre",
          )
        }}
      </p>
      <div class="mt-6 flex flex-wrap items-center gap-4">
        <RouterLink
          v-if="puedeEntrar('agenda', sesion)"
          :to="{ name: 'agenda' }"
          class="tu-btn tu-btn-primario inline-flex"
        >
          {{ $t("operacion.hoy.abrirAgenda") }}
        </RouterLink>
        <RouterLink
          v-if="puedeEntrar('recepcion', sesion)"
          :to="{ name: 'recepcion' }"
          class="tu-enlace text-sm"
        >
          {{ $t("operacion.hoy.irRecepcion") }}
        </RouterLink>
      </div>
    </TarjetaPrincipal>

    <TarjetasIndicadores
      v-if="indicadores.length > 0"
      class="mt-4"
      :tarjetas="indicadores"
      compacta
    />

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <!-- Agenda de hoy -->
      <section
        v-if="hoy.agenda"
        class="tu-card p-5 lg:col-span-2"
        aria-labelledby="hoy-agenda"
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 id="hoy-agenda" class="hoy-seccion">
            <IconoNav nombre="agenda" :tam="20" />
            {{ $t("operacion.hoy.agenda") }}
          </h2>
          <RouterLink
            v-if="puedeEntrar('agenda', sesion)"
            :to="{ name: 'agenda' }"
            class="tu-enlace text-sm"
          >
            {{ $t("operacion.hoy.verAgenda") }}
          </RouterLink>
        </div>
        <div v-if="hoy.agenda.sesiones.length > 0" class="hoy-filtros">
          <label class="tu-campo-icono hoy-buscar">
            <IconoNav nombre="buscar" :tam="17" />
            <input
              v-model="busqueda"
              type="search"
              class="tu-input"
              :aria-label="$t('operacion.hoy.buscar')"
              :placeholder="$t('operacion.hoy.buscar')"
            />
          </label>
          <div
            class="tu-segmentado"
            role="group"
            :aria-label="$t('operacion.hoy.filtrar')"
          >
            <button
              v-for="op in ['todas', 'proximas', 'canceladas'] as const"
              :key="op"
              type="button"
              :aria-pressed="filtro === op"
              @click="filtro = op"
            >
              {{ $t(`operacion.hoy.filtros.${op}`) }}
            </button>
          </div>
        </div>
        <EstadoVacio
          v-if="hoy.agenda.sesiones.length === 0"
          icono="agenda"
          compacto
          class="py-8"
          :titulo="
            $t(
              esCitas
                ? 'operacion.hoy.citas.sinCitas'
                : 'operacion.hoy.sinSesiones',
            )
          "
        />
        <EstadoVacio
          v-else-if="agendaVisible.length === 0"
          icono="buscar"
          compacto
          class="py-8"
          :titulo="$t('operacion.hoy.sinResultados')"
        >
          <button type="button" class="tu-btn tu-btn-fantasma" @click="limpiar">
            {{ $t("operacion.hoy.limpiar") }}
          </button>
        </EstadoVacio>
        <ul v-else class="hoy-agenda-lista">
          <li
            v-for="s in agendaVisible"
            :key="s.id"
            class="hoy-sesion"
            :class="{
              'hoy-en-curso': s.momento === 'en_curso',
              'hoy-cancelada': s.cancelada,
            }"
            :style="s.cancelada ? { color: 'var(--texto-suave)' } : undefined"
          >
            <span class="hoy-hora">{{ hora(s) }}</span>
            <span class="min-w-0 flex-1">
              <span
                class="block font-semibold hoy-nombre"
                :class="{ 'line-through': s.cancelada }"
                >{{ esCitas && s.cliente ? s.cliente : nombre(s) }}</span
              >
              <span
                class="block text-sm hoy-detalle"
                :style="{ color: 'var(--texto-suave)' }"
                >{{
                  [esCitas ? s.oferta : null, s.instructor, s.sucursal]
                    .filter(Boolean)
                    .join(" · ")
                }}</span
              >
              <RouterLink
                v-if="
                  !s.cancelada &&
                  s.sin_marcar > 0 &&
                  sesion.puede('asistencia.marcar') &&
                  puedeEntrar(esCitas ? 'agenda' : 'recepcion', sesion)
                "
                :to="{ name: esCitas ? 'agenda' : 'recepcion' }"
                class="mt-0.5 block text-sm"
                :style="{ color: 'var(--aviso)' }"
                >{{
                  s.tipo === "cita"
                    ? $t("operacion.hoy.citas.marcarLlegada")
                    : $t("operacion.hoy.pasarLista", s.sin_marcar)
                }}</RouterLink
              >
              <span
                v-if="s.tipo === 'cita' && !s.cancelada && s.por_cobrar"
                class="mt-0.5 block text-sm"
                :style="{ color: 'var(--aviso)' }"
                data-prueba="cita-por-cobrar"
                >{{ $t("operacion.hoy.citas.porCobrar") }}</span
              >
              <span
                v-if="s.tipo !== 'cita' && (s.en_espera ?? 0) > 0"
                class="mt-0.5 block text-sm"
                :style="{ color: 'var(--texto-suave)' }"
                >{{
                  $t("operacion.hoy.clases.enEspera", s.en_espera ?? 0)
                }}</span
              >
            </span>
            <span class="hoy-estado text-sm">
              <span
                v-if="s.tipo !== 'cita' && !s.cancelada"
                class="block tabular-nums"
                >{{
                  s.capacidad
                    ? $t("operacion.hoy.cupo", {
                        n: s.esperados,
                        total: s.capacidad,
                      })
                    : s.esperados
                }}</span
              >
              <span
                v-if="s.tipo !== 'cita' && !s.cancelada && s.capacidad"
                class="hoy-ocupacion"
                aria-hidden="true"
                ><span :style="{ width: `${ocupacion(s)}%` }"
              /></span>
              <span
                v-if="s.tipo === 'cita' && !s.cancelada && s.llegaron > 0"
                class="hoy-status"
                :style="{ '--tono': 'var(--exito-texto, var(--exito))' }"
                >{{ $t("operacion.hoy.citas.llego") }}</span
              >
              <span
                v-else
                class="hoy-status"
                :style="{
                  '--tono':
                    s.momento === 'en_curso'
                      ? 'var(--primario)'
                      : 'var(--texto-suave)',
                }"
                >{{ $t(`operacion.hoy.momento.${s.momento}`) }}</span
              >
            </span>
          </li>
        </ul>
      </section>

      <div
        v-if="libres || hoy.cobros || hoy.renovaciones"
        class="grid content-start gap-6"
        :class="{ 'lg:col-span-3': !hoy.agenda }"
      >
        <!-- Espacios libres de hoy (citas) -->
        <section
          v-if="libres"
          class="tu-card p-5"
          aria-labelledby="hoy-libres"
          data-prueba="libres"
        >
          <div class="flex items-baseline justify-between gap-3">
            <h2 id="hoy-libres" class="hoy-seccion">
              <IconoNav nombre="reloj" :tam="20" />
              {{ $t("operacion.hoy.citas.libres") }}
            </h2>
            <RouterLink
              v-if="puedeEntrar('agenda', sesion)"
              :to="{ name: 'agenda' }"
              class="tu-enlace text-sm"
            >
              {{ $t("operacion.hoy.citas.agendar") }}
            </RouterLink>
          </div>
          <p
            v-if="libres.length === 0"
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("operacion.hoy.citas.nadieAtiende") }}
          </p>
          <ul v-else class="mt-3 divide-y divide-[var(--borde)] text-sm">
            <li
              v-for="l in libres"
              :key="`${l.profesional}-${l.sucursal}`"
              class="flex items-center justify-between gap-3 py-2.5"
            >
              <span class="min-w-0">
                <span class="block truncate font-medium">{{
                  l.profesional
                }}</span>
                <span
                  v-if="l.sucursal"
                  class="block truncate"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ l.sucursal }}</span
                >
              </span>
              <span class="shrink-0 text-right">
                <span class="block tabular-nums">{{
                  l.huecos > 0
                    ? $t("operacion.hoy.citas.huecos", l.huecos)
                    : $t("operacion.hoy.citas.sinHuecos")
                }}</span>
                <span
                  v-if="l.siguiente"
                  class="block tabular-nums"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{
                    $t("operacion.hoy.citas.desde", {
                      hora: horaIso(l.siguiente, l.zona_horaria),
                    })
                  }}</span
                >
              </span>
            </li>
          </ul>
        </section>

        <!-- Pendientes: cobros y renovaciones -->
        <section
          v-if="hoy.cobros || hoy.renovaciones"
          class="tu-card p-5"
          aria-labelledby="hoy-pendientes"
        >
          <h2 id="hoy-pendientes" class="hoy-seccion">
            <IconoNav nombre="pulso" :tam="20" />
            {{ $t("operacion.hoy.pendientes") }}
          </h2>
          <EstadoVacio
            v-if="!hayPendientes"
            icono="hecho"
            compacto
            class="py-8"
            :titulo="$t('operacion.hoy.alDia')"
          />
          <ul v-else class="mt-3 space-y-2 text-sm">
            <li v-if="hoy.cobros && hoy.cobros.ordenes_pendientes > 0">
              <RouterLink :to="{ name: 'cobranza' }" class="hoy-pendiente">
                <span class="tu-icono-tono tu-tono-naranja" aria-hidden="true">
                  <IconoNav nombre="dinero" :tam="18" />
                </span>
                <span class="hoy-texto">{{
                  $t("operacion.hoy.porCobrar", hoy.cobros.ordenes_pendientes)
                }}</span>
                <span class="tabular-nums font-medium">{{ porCobrar }}</span>
              </RouterLink>
            </li>
            <li v-if="hoy.cobros && hoy.cobros.en_mora > 0">
              <RouterLink :to="{ name: 'cobranza' }" class="hoy-pendiente">
                <span class="tu-icono-tono tu-tono-rosa" aria-hidden="true">
                  <IconoNav nombre="facturas" :tam="18" />
                </span>
                <span class="hoy-texto" :style="{ color: 'var(--aviso)' }">{{
                  $t("operacion.hoy.enMora", hoy.cobros.en_mora)
                }}</span>
              </RouterLink>
            </li>
            <li v-if="hoy.renovaciones && hoy.renovaciones.por_vencer > 0">
              <RouterLink :to="{ name: 'retencion' }" class="hoy-pendiente">
                <span class="tu-icono-tono tu-tono-morado" aria-hidden="true">
                  <IconoNav nombre="reloj" :tam="18" />
                </span>
                <span class="hoy-texto">{{
                  $t(
                    "operacion.hoy.porVencer",
                    {
                      n: hoy.renovaciones.por_vencer,
                      dias: hoy.renovaciones.dias,
                    },
                    hoy.renovaciones.por_vencer,
                  )
                }}</span>
              </RouterLink>
            </li>
            <li v-if="hoy.renovaciones && hoy.renovaciones.vencidas > 0">
              <RouterLink :to="{ name: 'retencion' }" class="hoy-pendiente">
                <span class="tu-icono-tono tu-tono-rosa" aria-hidden="true">
                  <IconoNav nombre="pulso" :tam="18" />
                </span>
                <span class="hoy-texto" :style="{ color: 'var(--aviso)' }">{{
                  $t("operacion.hoy.vencidas", hoy.renovaciones.vencidas)
                }}</span>
              </RouterLink>
            </li>
          </ul>
        </section>
      </div>
    </div>
  </template>
</template>

<style scoped>
.hoy-seccion {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  font-weight: 600;
}
.hoy-seccion > svg {
  color: var(--primario);
  flex-shrink: 0;
}
.hoy-filtros {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-top: 1rem;
}
.hoy-buscar {
  flex: 1 1 14rem;
  min-width: 0;
}
.hoy-agenda-lista {
  display: grid;
  gap: 0.65rem;
  margin-top: 1rem;
}
.hoy-sesion {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  padding: 0.9rem;
  border: 1px solid var(--borde);
  border-radius: 0.85rem;
  background: var(--superficie);
}
.hoy-en-curso {
  border-left: 3px solid var(--primario);
  background: color-mix(in srgb, var(--primario) 4%, var(--superficie));
}
.hoy-cancelada {
  background: var(--superficie-2);
}
.hoy-hora {
  flex-shrink: 0;
  padding: 0.4rem 0.5rem;
  border-radius: 0.55rem;
  background: var(--primario-suave);
  color: var(--primario);
  font-size: 0.85rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.hoy-cancelada .hoy-hora {
  background: var(--superficie-2);
  color: var(--texto-suave);
}
.hoy-nombre,
.hoy-detalle {
  overflow-wrap: anywhere;
}
.hoy-detalle {
  margin-top: 0.2rem;
}
.hoy-estado {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.35rem;
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
}
.hoy-status {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.75rem;
  color: var(--tono);
}
.hoy-status::before {
  content: "";
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 50%;
  background: currentColor;
}
.hoy-ocupacion {
  width: 3.5rem;
  height: 0.25rem;
  overflow: hidden;
  background: var(--superficie-2);
  border-radius: 1rem;
}
.hoy-ocupacion > span {
  display: block;
  height: 100%;
  background: var(--primario);
}
@media (max-width: 600px) {
  .hoy-sesion {
    flex-wrap: wrap;
  }
  .hoy-estado {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    margin-left: 4rem;
  }
  .hoy-filtros .tu-segmentado {
    width: 100%;
  }
  .hoy-filtros .tu-segmentado button {
    flex: 1;
    min-height: 44px;
  }
}
.hoy-pendiente {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.65rem 0.35rem;
  min-height: 44px;
  border-radius: 0.75rem;
  color: var(--texto);
}
.hoy-pendiente:hover {
  background: var(--superficie-2);
}
.hoy-texto {
  flex: 1;
  min-width: 0;
}
</style>
