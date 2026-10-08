<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AgregarAClase from "@/components/AgregarAClase.vue";
import ConfirmarCancelacion from "@/components/ConfirmarCancelacion.vue";
import AvatarIniciales from "@/components/AvatarIniciales.vue";
import IconoNav from "@/components/IconoNav.vue";
import MoverReserva from "@/components/MoverReserva.vue";
import MarcoDetalle from "@/components/MarcoDetalle.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import {
  colorEstado,
  estadoEnLista,
  ocupaLugar,
  resumenLista,
  type ReservaLista,
} from "@/lib/paseLista";
import { useSesionTenantStore } from "@/stores/sesionTenant";

type Reserva = ReservaLista;
export interface SesionResumen {
  id: string;
  oferta: string | null;
  instructor: string | null;
  inicia_en: string;
  zona_horaria: string;
  capacidad: number | null;
  oferta_id?: string | null;
}

// Detalle de una clase (Recepción, Mis clases): quién viene y cómo va, la lista de
// espera y lo que se gestiona de cada lugar. La asistencia se pasa en su propia
// pantalla (Pasar lista).
// `incrustado`: se pinta junto a la lista (escritorio); si no, como panel (móvil).
const props = defineProps<{ sesion: SesionResumen; incrustado?: boolean }>();
const emit = defineEmits<{ (e: "cerrar"): void; (e: "cambio"): void }>();

const { t } = useI18n();
const sesionStore = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesionStore.slug}`);
// Pasar lista: la pantalla de la lista pide ver las reservas y marcar asistencia.
const puedePasarLista = computed(
  () =>
    sesionStore.puede("asistencia.marcar") && sesionStore.puede("reservas.ver"),
);
const puedeGestionar = computed(() => sesionStore.puede("reservas.gestionar"));

const roster = ref<Reserva[]>([]);
const cargando = ref(true);
const accionando = ref(false);
const error = ref<string | null>(null);
const aviso = ref<string | null>(null);

// Confirmadas, ofrecidas y pendientes de pago (todas ocupan lugar); en espera aparte.
const enSala = computed(() => roster.value.filter(ocupaLugar));
const enEspera = computed(() =>
  roster.value.filter((r) => r.estado === "en_espera"),
);
const resumen = computed(() =>
  resumenLista(roster.value, props.sesion.capacidad),
);
const libres = computed(() => resumen.value.libres);

/**
 * Avisos de un alumno en una sola línea de texto: en ámbar lo que hay que atender
 * (o saber, como la primera visita) y el adeudo en rojo; sin píldoras.
 */
function avisos(
  r: Reserva,
): { texto: string; color: string; ayuda?: string }[] {
  const out: { texto: string; color: string; ayuda?: string }[] = [];
  if (r.estado === "ofrecida") {
    out.push({ texto: t("agenda.roster.ofrecida"), color: "var(--aviso)" });
  }
  if (r.estado === "pendiente_pago") {
    out.push({
      texto: t("agenda.roster.pendiente_pago"),
      color: "var(--aviso)",
    });
  }
  if (r.adeudo) {
    out.push({ texto: t("agenda.roster.adeudo"), color: "var(--error)" });
  }
  if (r.documentos_pendientes > 0) {
    out.push({
      texto: t("agenda.roster.documentos"),
      color: "var(--aviso)",
      ayuda: t("agenda.roster.documentosAyuda"),
    });
  }
  if (r.primera_vez) {
    out.push({
      texto: t("agenda.roster.primeraVez"),
      color: "var(--aviso)",
      ayuda: t("agenda.roster.primeraVezAyuda"),
    });
  }
  return out;
}

const subtitulo = computed(() =>
  [
    hora(props.sesion.inicia_en, props.sesion.zona_horaria),
    props.sesion.instructor,
  ]
    .filter(Boolean)
    .join(" · "),
);

/** «Jueves 10 de enero, 19:00»: la fecha completa (no solo la hora). */
function hora(iso: string, zona: string): string {
  const texto = new Intl.DateTimeFormat("es-MX", {
    timeZone: zona,
    weekday: "long",
    day: "numeric",
    month: "long",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
  return texto.charAt(0).toUpperCase() + texto.slice(1);
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Reserva[] }>(
      `${base.value}/sesiones/${props.sesion.id}/reservas`,
    );
    roster.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function accion(fn: () => Promise<unknown>): Promise<void> {
  accionando.value = true;
  error.value = null;
  aviso.value = null;
  try {
    await fn();
    await cargar();
    emit("cambio");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

function aceptar(r: Reserva): Promise<void> {
  return accion(() => api.post(`${base.value}/reservas/${r.id}/aceptar`, {}));
}
// Reserva cuya cancelación se está confirmando (con su efecto a la vista).
const cancelando = ref<string | null>(null);
// Reserva que se está moviendo a otra fecha de la clase (2.1).
const moviendo = ref<string | null>(null);
async function movida(): Promise<void> {
  moviendo.value = null;
  aviso.value = t("reprogramar.movida");
  await cargar();
  emit("cambio");
}
async function cancelar(
  r: Reserva,
  por: "cliente" | "negocio" | null = null,
): Promise<void> {
  await accion(() =>
    api.post(`${base.value}/reservas/${r.id}/cancelar`, por ? { por } : {}),
  );
  cancelando.value = null;
}

async function promover(): Promise<void> {
  if (
    !(await confirmar(t("confirmaciones.promover"), {
      aceptar: t("confirmaciones.promoverAceptar"),
    }))
  ) {
    return;
  }
  accionando.value = true;
  error.value = null;
  aviso.value = null;
  try {
    const { data } = await api.post<{ data: { ofrecidas: number } }>(
      `${base.value}/sesiones/${props.sesion.id}/promover`,
      {},
    );
    const n = data.data.ofrecidas;
    aviso.value =
      n > 0
        ? t("oportunidades.ofrecidas", { n })
        : t("oportunidades.sinPromover");
    await cargar();
    emit("cambio");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

// Walk-in: agregar a alguien a la clase en el momento (o a la lista de espera).
const agregando = ref(false);
async function agregado(): Promise<void> {
  agregando.value = false;
  await cargar();
  emit("cambio");
}

watch(() => props.sesion.id, cargar, { immediate: true });
</script>

<template>
  <MarcoDetalle
    :incrustado="incrustado"
    :etiqueta="sesionStore.terminologia.sesion"
    :titulo="sesion.oferta ?? '—'"
    :subtitulo="subtitulo"
    :etiqueta-cerrar="$t('recepcion.panel.cerrar')"
    @cerrar="emit('cerrar')"
  >
    <template #destacado>
      <span
        v-if="!cargando && !error && libres !== null"
        class="md-pastilla"
        :class="{ 'md-pastilla-aviso': libres === 0 }"
        >{{
          libres === 0
            ? $t("recepcionVisual.llena")
            : $t("recepcionVisual.lugaresDisponibles", { n: libres }, libres)
        }}</span
      >
      <div v-if="!cargando && !error" class="pc-lista-resumen">
        <div>
          <IconoNav nombre="miembros" :tam="18" /><strong>{{
            enSala.length
          }}</strong
          ><span>{{ $t("portal.instructor.lista.reservas") }}</span>
        </div>
        <div class="pc-lista-presentes">
          <IconoNav nombre="hecho" :tam="18" /><strong>{{
            resumen.llegaron
          }}</strong
          ><span>{{ $t("portal.instructor.lista.presentes") }}</span>
        </div>
        <div>
          <IconoNav nombre="reloj" :tam="18" /><strong>{{
            resumen.porMarcar
          }}</strong
          ><span>{{ $t("portal.instructor.lista.sinMarcar") }}</span>
        </div>
      </div>
      <!-- La asistencia se pasa en su propia pantalla -->
      <RouterLink
        v-if="!cargando && !error && puedePasarLista && enSala.length > 0"
        :to="{ name: 'pase-lista', params: { id: sesion.id } }"
        class="tu-btn tu-btn-primario mt-3 inline-flex items-center gap-2"
        data-prueba="pasar-lista"
      >
        <IconoNav nombre="lista" :tam="16" />
        {{ $t("paseLista.pasarLista") }}
      </RouterLink>
      <p
        v-if="!cargando && !error && enEspera.length > 0"
        class="mt-3 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        <span :style="{ color: 'var(--aviso)' }">{{
          $t("recepcion.panel.espera", { n: enEspera.length })
        }}</span>
      </p>
      <!-- Acciones de quien lo abre (p. ej. agregar a mi calendario) -->
      <div v-if="$slots.acciones" class="mt-3"><slot name="acciones" /></div>
    </template>

    <p v-if="aviso" class="mb-3 text-sm" style="color: var(--exito)">
      {{ aviso }}
    </p>
    <p v-if="error" class="mb-3 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <!-- Walk-in: agregar alumno en el momento (a la clase o a la lista de espera) -->
    <div v-if="puedeGestionar" class="mb-4">
      <button
        v-if="!agregando"
        type="button"
        class="tu-btn tu-btn-fantasma text-xs px-3 py-1.5"
        @click="agregando = true"
      >
        {{ $t("recepcion.panel.agregar") }}
      </button>
      <AgregarAClase
        v-else
        :base="base"
        :sesion-id="sesion.id"
        :llena="libres === 0"
        @agregado="agregado"
        @cerrar="agregando = false"
      />
    </div>

    <p v-if="cargando" class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <p
        v-if="roster.length === 0"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("agenda.roster.vacio") }}
      </p>

      <!-- En sala: confirmadas + ofrecidas (check-in) -->
      <ul
        v-if="enSala.length > 0"
        class="rounded-xl border px-4"
        :style="{
          background: 'var(--superficie)',
          borderColor: 'var(--borde)',
        }"
      >
        <li
          v-for="r in enSala"
          :key="r.id"
          class="border-t py-3 first:border-t-0"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <div class="flex items-center gap-3">
            <AvatarIniciales :nombre="r.persona" tam="md" />
            <div class="min-w-0 flex-1">
              <RouterLink
                v-if="r.persona_id && sesionStore.puede('miembros.ver')"
                :to="{
                  name: 'ficha-miembro',
                  params: { id: r.persona_id },
                }"
                class="block font-medium break-words hover:underline"
                >{{ r.persona ?? "—" }}</RouterLink
              >
              <span v-else class="block font-medium break-words">{{
                r.persona ?? "—"
              }}</span>
              <p v-if="avisos(r).length > 0" class="text-xs">
                <span
                  v-for="(a, i) in avisos(r)"
                  :key="a.texto"
                  :style="{ color: a.color }"
                  :title="a.ayuda"
                  >{{ i > 0 ? " · " : "" }}{{ a.texto }}</span
                >
              </p>
            </div>
            <span
              v-if="r.estado === 'confirmada' && r.asistencia"
              class="pc-estado shrink-0"
              :style="{ '--punto': colorEstado(estadoEnLista(r)) }"
              data-prueba="estado-asistencia"
              >{{ $t(`paseLista.estados.${estadoEnLista(r)}`) }}</span
            >
          </div>
          <div
            v-if="puedeGestionar"
            class="mt-2 flex flex-wrap items-center gap-2"
          >
            <button
              v-if="r.estado === 'ofrecida' && puedeGestionar"
              type="button"
              class="tu-btn tu-btn-primario text-xs px-3 py-1.5"
              :disabled="accionando"
              @click="aceptar(r)"
            >
              {{ $t("agenda.roster.aceptar") }}
            </button>
            <button
              v-if="
                puedeGestionar &&
                !r.asistencia &&
                r.estado === 'confirmada' &&
                sesion.oferta_id
              "
              type="button"
              class="tu-enlace text-xs"
              :aria-expanded="moviendo === r.id"
              @click="moviendo = moviendo === r.id ? null : r.id"
            >
              {{ $t("reprogramar.moverTitulo") }}
            </button>
            <!-- Con asistencia ya no se cancela (se corrige con Llegó / No vino). -->
            <button
              v-if="puedeGestionar && !r.asistencia"
              type="button"
              class="tu-enlace text-xs ml-auto"
              style="color: var(--error)"
              :disabled="accionando"
              :aria-expanded="cancelando === r.id"
              @click="cancelando = cancelando === r.id ? null : r.id"
            >
              {{ $t("agenda.roster.cancelarReserva") }}
            </button>
          </div>
          <MoverReserva
            v-if="moviendo === r.id && sesion.oferta_id"
            :base="base"
            :reserva-id="r.id"
            :sesion-id="sesion.id"
            :oferta-id="sesion.oferta_id"
            @hecho="movida"
            @cerrar="moviendo = null"
          />
          <ConfirmarCancelacion
            v-if="cancelando === r.id"
            :url="`${base}/reservas/${r.id}/cancelacion`"
            con-quien
            :ocupado="accionando"
            @confirmar="(por) => cancelar(r, por)"
            @cerrar="cancelando = null"
          />
        </li>
      </ul>

      <!-- Lista de espera -->
      <div
        v-if="enEspera.length > 0"
        class="mt-5 border-t pt-4"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <div class="flex items-center justify-between gap-2">
          <h3 class="text-sm font-semibold">
            {{ $t("recepcion.panel.listaEspera") }}
          </h3>
          <button
            v-if="puedeGestionar"
            type="button"
            class="tu-btn tu-btn-fantasma text-xs px-3 py-1.5"
            :disabled="accionando"
            @click="promover"
          >
            {{ $t("recepcion.panel.promover") }}
          </button>
        </div>
        <ul class="mt-2 space-y-1.5">
          <li
            v-for="r in enEspera"
            :key="r.id"
            class="flex items-center justify-between gap-2 text-sm"
          >
            <span class="truncate">{{ r.persona ?? "—" }}</span>
            <button
              v-if="puedeGestionar"
              type="button"
              class="tu-enlace text-xs shrink-0"
              style="color: var(--error)"
              :disabled="accionando"
              @click="cancelar(r)"
            >
              {{ $t("agenda.roster.cancelarReserva") }}
            </button>
          </li>
        </ul>
      </div>
    </template>
  </MarcoDetalle>
</template>

<style scoped>
.pc-lista-resumen {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.65rem;
  margin-top: 1rem;
}
.pc-lista-resumen > div {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  align-items: center;
  padding: 0.75rem;
  border: 1px solid var(--borde);
  background: var(--superficie);
  border-radius: 10px;
}
.pc-lista-resumen strong {
  font-size: 1.25rem;
}
.pc-lista-resumen span {
  width: 100%;
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.pc-lista-resumen svg {
  color: var(--texto-suave);
}
.pc-lista-presentes strong,
.pc-lista-presentes svg {
  color: var(--exito-texto);
}
/* Estado de asistencia: punto de color + texto. */
.pc-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.8rem;
  font-weight: 500;
}
.pc-estado::before {
  content: "";
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: var(--punto);
}
@media (max-width: 400px) {
  .pc-lista-resumen > div {
    padding: 0.55rem;
  }
}
</style>
