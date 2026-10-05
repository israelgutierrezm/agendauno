<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import EstadoVacio from "@/components/EstadoVacio.vue";
import AvatarIniciales from "@/components/AvatarIniciales.vue";
import IconoNav from "@/components/IconoNav.vue";
import PanelCita from "@/components/PanelCita.vue";
import {
  estadoCita,
  fechaLocal,
  pagoCita,
  type SesionAgenda,
} from "@/lib/agenda";
import { api, mensajeDeError } from "@/lib/api";
import { normalizar } from "@/lib/menu";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Recepción en un negocio de citas: la jornada como una lista de clientes, no de
 * «lugares» (una cita no se llena ni tiene cupo). A qué hora, quién, qué servicio y
 * con quién; si ya llegó y si falta cobrar. Tocar una abre la misma cita que en la
 * agenda (marcar llegada, cobrar, cambiar o cancelar). Avisa los números del día
 * (`resumen`) para los indicadores de arriba.
 */
export interface ResumenCitas {
  citas: number;
  llegaron: number;
  porAtender: number;
  porCobrar: number;
}

const props = defineProps<{ fecha: string; sucursalId: string }>();
const emit = defineEmits<{ resumen: [r: ResumenCitas]; cambio: [] }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeMarcar = computed(() => sesion.puede("asistencia.marcar"));
const puedeCobrar = computed(() => sesion.puede("ordenes.gestionar"));
const puedeCancelar = computed(() => sesion.puede("reservas.gestionar"));

const citas = ref<SesionAgenda[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const abierta = ref<SesionAgenda | null>(null);
const busqueda = ref("");
const filtro = ref<"todas" | "pendientes" | "llegaron" | "canceladas">("todas");
const visibles = computed(() => {
  const q = normalizar(busqueda.value.trim());
  return citas.value.filter((s) => {
    const estado = estadoCita(s, new Date());
    const coincide =
      filtro.value === "todas" ||
      (filtro.value === "pendientes" && estado === "confirmada") ||
      (filtro.value === "llegaron" &&
        s.estado === "programada" &&
        s.cita?.asistencia === "presente") ||
      (filtro.value === "canceladas" && estado === "cancelada");
    return (
      coincide &&
      (!q ||
        normalizar(
          [s.cita?.asiste, s.cita?.cliente, s.oferta, s.instructor, s.sucursal]
            .filter(Boolean)
            .join(" "),
        ).includes(q))
    );
  });
});
function limpiar(): void {
  busqueda.value = "";
  filtro.value = "todas";
}

let pedido = 0;
async function cargar(): Promise<void> {
  const mio = ++pedido;
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: SesionAgenda[] }>(
      `${base.value}/sesiones`,
      {
        params: {
          desde: props.fecha,
          hasta: props.fecha,
          ...(props.sucursalId !== "" ? { sucursal_id: props.sucursalId } : {}),
        },
      },
    );
    if (mio !== pedido) {
      return;
    }
    // El servidor ensancha el rango por las zonas horarias: solo las de ese día.
    citas.value = data.data
      .filter(
        (s) =>
          s.tipo === "cita" &&
          fechaLocal(s.inicia_en, s.zona_horaria) === props.fecha,
      )
      .sort((a, b) => a.inicia_en.localeCompare(b.inicia_en));
    if (abierta.value) {
      abierta.value =
        citas.value.find((s) => s.id === abierta.value?.id) ?? null;
    }
  } catch (e) {
    if (mio === pedido) {
      error.value = mensajeDeError(e);
    }
  } finally {
    if (mio === pedido) {
      cargando.value = false;
    }
  }
}
watch(() => [props.fecha, props.sucursalId], cargar, { immediate: true });

const activas = computed(() =>
  citas.value.filter((s) => s.estado === "programada" && s.cita),
);
const resumen = computed<ResumenCitas>(() => {
  const ahora = new Date();
  return {
    citas: activas.value.length,
    llegaron: activas.value.filter((s) => s.cita?.asistencia === "presente")
      .length,
    porAtender: activas.value.filter(
      (s) => estadoCita(s, ahora) === "confirmada",
    ).length,
    porCobrar: activas.value.filter((s) => {
      const p = pagoCita(s);
      return p === "por_cobrar" || p === "por_pagar";
    }).length,
  };
});
watch(resumen, (r) => emit("resumen", r), { immediate: true });

function hora(s: SesionAgenda): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: s.zona_horaria,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(s.inicia_en));
}
// Atención: agendada, llegó, en servicio, completada, no asistió o cancelada.
function atencion(s: SesionAgenda): { texto: string; tono: string } {
  const e = estadoCita(s, new Date());
  const tono: Record<string, string> = {
    llego: "var(--exito)",
    en_servicio: "var(--exito)",
    completada: "var(--texto-suave)",
    no_asistio: "var(--error)",
    cancelada: "var(--texto-suave)",
  };
  return {
    texto: t(`agendaVisual.estadosCita.${e}`),
    tono: tono[e] ?? "var(--primario)",
  };
}
// Pago, aparte de la atención: solo si falta.
function pago(s: SesionAgenda): string | null {
  const p = pagoCita(s);
  if (p === "por_cobrar") {
    return t("agendaVisual.cita.porCobrar");
  }
  return p === "por_pagar" ? t("agendaVisual.cita.pagoEnLinea") : null;
}
const catalogo = computed(
  () =>
    [
      ...new Set(citas.value.map((s) => s.oferta_id).filter(Boolean)),
    ] as string[],
);

async function alCambiar(): Promise<void> {
  await cargar();
  emit("cambio");
}

defineExpose({ cargar });
</script>

<template>
  <div>
    <div v-if="!error && citas.length > 0" class="rc-filtros">
      <label class="tu-campo-icono rc-buscar">
        <IconoNav nombre="buscar" :tam="18" />
        <input
          v-model="busqueda"
          class="tu-input"
          type="search"
          :placeholder="$t('operacion.admin.buscarJornada')"
          :aria-label="$t('operacion.admin.buscarJornada')"
        />
      </label>
      <div
        class="tu-segmentado rc-segmentado"
        role="group"
        :aria-label="$t('operacion.admin.filtrarAtencion')"
      >
        <button
          v-for="op in [
            'todas',
            'pendientes',
            'llegaron',
            'canceladas',
          ] as const"
          :key="op"
          type="button"
          :aria-pressed="filtro === op"
          @click="filtro = op"
        >
          {{ $t(`operacion.admin.atencion.${op}`) }}
        </button>
      </div>
    </div>
    <p
      v-if="cargando && citas.length === 0"
      class="px-5 py-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <p v-else-if="error" class="px-5 py-12 text-center text-sm">
      <span class="block" style="color: var(--error)">{{ error }}</span>
      <button type="button" class="tu-enlace mt-2" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </p>
    <EstadoVacio
      v-else-if="citas.length === 0"
      class="py-12"
      icono="agenda"
      :titulo="$t('recepcionVisual.citas.sinCitas')"
    />
    <EstadoVacio
      v-else-if="visibles.length === 0"
      class="py-12"
      icono="buscar"
      :titulo="$t('operacion.admin.sinResultados')"
    >
      <button type="button" class="tu-btn tu-btn-fantasma" @click="limpiar">
        {{ $t("operacion.admin.limpiar") }}
      </button>
    </EstadoVacio>
    <ul v-else class="rc-lista" data-prueba="recepcion-citas">
      <li v-for="s in visibles" :key="s.id">
        <button
          type="button"
          class="rc-fila"
          :class="{ 'rc-cancelada': s.estado !== 'programada' }"
          @click="abierta = s"
        >
          <span class="rc-hora tabular-nums">{{ hora(s) }}</span>
          <AvatarIniciales
            :nombre="s.cita?.asiste || s.cita?.cliente || ''"
            tam="md"
            class="rc-avatar"
          />
          <span class="min-w-0 flex-1">
            <span class="block font-semibold rc-nombre">{{
              s.cita?.asiste ||
              s.cita?.cliente ||
              $t("recepcionVisual.citas.sinCliente")
            }}</span>
            <span
              class="block text-sm rc-detalle"
              :style="{ color: 'var(--texto-suave)' }"
              >{{
                [s.oferta, s.instructor, s.sucursal].filter(Boolean).join(" · ")
              }}</span
            >
          </span>
          <span class="rc-estado">
            <span class="tu-pildora" :style="{ '--tono': atencion(s).tono }">{{
              atencion(s).texto
            }}</span>
            <span
              v-if="pago(s)"
              class="tu-pildora"
              :style="{ '--tono': 'var(--aviso)' }"
              >{{ pago(s) }}</span
            >
          </span>
          <IconoNav nombre="chevron" :tam="16" class="rc-chevron" />
        </button>
      </li>
    </ul>

    <PanelCita
      :abierto="abierta !== null"
      :base="base"
      :sesion="abierta"
      :catalogo="catalogo"
      :puede-marcar="puedeMarcar"
      :puede-cobrar="puedeCobrar"
      :puede-cancelar="puedeCancelar"
      @cerrar="abierta = null"
      @cambiada="alCambiar"
    />
  </div>
</template>

<style scoped>
.rc-filtros {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  padding: 1rem;
  border-bottom: 1px solid var(--borde);
}
.rc-buscar {
  flex: 1 1 18rem;
  min-width: 0;
}
.rc-lista {
  display: grid;
  gap: 0.6rem;
  padding: 1rem;
}
.rc-nombre,
.rc-detalle {
  overflow-wrap: anywhere;
}
.rc-detalle {
  margin-top: 0.2rem;
}
.rc-chevron {
  flex-shrink: 0;
  color: var(--texto-suave);
}
/* Una cita por fila: la hora, quién y qué; su atención y su pago a la derecha. */
.rc-fila {
  display: flex;
  width: 100%;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 1rem;
  padding: 0.95rem;
  border: 1px solid var(--borde);
  border-radius: 0.85rem;
  background: var(--superficie);
  text-align: left;
}
.rc-fila:hover {
  background: var(--superficie-2);
  border-color: var(--primario);
}
.rc-fila:hover .font-medium {
  color: var(--primario);
}
.rc-hora {
  padding: 0.4rem 0.5rem;
  border-radius: 0.55rem;
  background: var(--primario-suave);
  color: var(--primario);
  font-size: 0.85rem;
  flex-shrink: 0;
  font-weight: 500;
}
.rc-estado {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-left: auto;
  font-size: 0.8rem;
}
.rc-cancelada {
  opacity: 0.6;
}
@media (max-width: 600px) {
  .rc-lista {
    padding: 0.75rem;
  }
  .rc-fila {
    gap: 0.65rem;
  }
  .rc-avatar {
    display: none;
  }
  .rc-estado {
    width: 100%;
    margin-left: 4rem;
  }
  .rc-chevron {
    display: none;
  }
  .rc-segmentado {
    display: flex;
    flex-wrap: wrap;
    width: 100%;
  }
  .rc-segmentado button {
    flex: 1;
    min-height: 44px;
  }
}
</style>
