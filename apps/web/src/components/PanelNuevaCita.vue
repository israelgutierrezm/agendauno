<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import BuscarPersona, {
  type PersonaBuscable,
} from "@/components/BuscarPersona.vue";
import CampoCelular from "@/components/CampoCelular.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import LeyendaSucursal from "@/components/LeyendaSucursal.vue";
import {
  diaIso,
  fechaLocal,
  minutosLocal,
  type BloqueoAgenda,
  type VentanaAtencion,
} from "@/lib/agenda";
import { dinero as dineroDelPais } from "@/lib/formato";
import { api, mensajeDeError } from "@/lib/api";
import { useDescartarCambios } from "@/lib/editorFormulario";
import { intentoUnico } from "@/lib/intento";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Alta de una CITA por el negocio (recepción, teléfono, mostrador). Guía antes de
 * guardar, en este orden: el cliente (se busca entre todos en el servidor o se da de
 * alta al vuelo), la sucursal, el servicio, el día, los profesionales que atienden ahí
 * (primero los que trabajan ese día) y los HORARIOS LIBRES de ese profesional (los
 * calcula el servidor con su horario, sus citas, los bloqueos y los márgenes del
 * servicio). Así recepción no descubre el conflicto al guardar.
 *
 * Quien puede gestionar la agenda conserva la captura manual de otra hora, para lo
 * excepcional: se dice que está fuera de los horarios libres y, si cae fuera de la
 * atención del profesional o choca con un bloqueo (ese no se podrá), también.
 * La cita queda confirmada; si el servicio es de pago, se cobra en caja.
 */
export interface OfertaCita {
  id: string;
  nombre: string;
  precio_clase_minor?: number | null;
  politica_reserva?: string;
  duracion_minutos?: number | null;
}

const props = defineProps<{
  abierto: boolean;
  base: string;
  ofertas: OfertaCita[];
  sucursales: { id: string; nombre: string }[];
  // Con las sucursales donde atiende cada uno (vacío = en todas).
  profesionales: { id: string; nombre: string; sucursales?: string[] }[];
  inicial: {
    fecha: string;
    hora: string;
    instructorId: string | null;
    sucursalId: string | null;
  };
  // Horario de atención y bloqueos ya cargados en la agenda, y su zona horaria.
  ventanas?: VentanaAtencion[];
  bloqueos?: BloqueoAgenda[];
  zona?: string;
}>();

const emit = defineEmits<{ cerrar: []; agendada: [] }>();

const { t } = useI18n();
const toast = useToastStore();
// La captura manual de una hora fuera de los libres: solo quien gestiona la agenda.
const puedeManual = computed(() =>
  useSesionTenantStore().puede("agenda.gestionar"),
);
const manual = ref(false);

const form = ref({
  clienteId: "",
  ofertaId: "",
  instructorId: "",
  sucursalId: "",
  fecha: "",
  hora: "",
  duracion: 30,
});
// El cliente elegido (su nombre, para el aviso al agendar).
const clienteSel = ref<PersonaBuscable | null>(null);
const nuevo = ref(false);
const puedeCrearCliente = computed(() =>
  useSesionTenantStore().puede("miembros.gestionar"),
);
const nuevoCliente = ref({ nombre: "", celular: "", whatsapp: false });
// Avisos por WhatsApp (ADR 0069): si el negocio los usa y el cliente dejó celular.
const ofrecerWhatsApp = computed(
  () =>
    useSesionTenantStore().estudio?.whatsapp_clientes === true &&
    nuevoCliente.value.celular.trim() !== "",
);
const guardando = ref(false);
const error = ref<string | null>(null);
// Con lo capturado sin agendar, cerrar o salir pregunta antes (patrón de formularios).
const abiertoAqui = computed({
  get: () => props.abierto,
  set: (v: boolean) => {
    if (!v) emit("cerrar");
  },
});
const descartar = useDescartarCambios({
  abierto: abiertoAqui,
  valor: () => ({
    form: form.value,
    cliente: clienteSel.value?.id ?? null,
    nuevo: nuevo.value,
    nuevoCliente: nuevoCliente.value,
  }),
  guardando,
});
function cerrar(): void {
  void descartar.cerrar();
}

// Al abrir, se precarga con el hueco elegido en la agenda.
watch(
  () => props.abierto,
  (abierto) => {
    if (!abierto) {
      return;
    }
    const primera = props.ofertas[0];
    form.value = {
      clienteId: "",
      ofertaId: primera?.id ?? "",
      instructorId:
        props.inicial.instructorId ?? props.profesionales[0]?.id ?? "",
      sucursalId: props.inicial.sucursalId ?? props.sucursales[0]?.id ?? "",
      fecha: props.inicial.fecha,
      hora: props.inicial.hora,
      duracion: primera?.duracion_minutos ?? 30,
    };
    clienteSel.value = null;
    nuevo.value = false;
    nuevoCliente.value = { nombre: "", celular: "", whatsapp: false };
    error.value = null;
    manual.value = false;
    // La hora que se tocó en la agenda se confirma con los horarios libres.
    horaPedida = props.inicial.hora;
    descartar.fijar();
  },
);

const oferta = computed(() =>
  props.ofertas.find((o) => o.id === form.value.ofertaId),
);

// ---- Profesionales que atienden en la sucursal elegida ----
function atiendeElDia(id: string): boolean | null {
  const f = form.value;
  const suyas = (props.ventanas ?? []).filter(
    (v) =>
      v.instructor_id === id &&
      (v.sucursal_id === null || v.sucursal_id === f.sucursalId),
  );
  // Sin horario cargado para esa persona: no se sabe (no se marca).
  if (suyas.length === 0 || f.fecha === "") {
    return null;
  }
  return suyas.some((v) => v.dia_semana === diaIso(f.fecha));
}
// Los de la sucursal (quien no tiene una propia atiende en todas), primero quienes
// trabajan ese día; los que no, al final y marcados.
const profesionalesCompatibles = computed(() =>
  props.profesionales
    .filter(
      (p) =>
        !p.sucursales?.length || p.sucursales.includes(form.value.sucursalId),
    )
    .map((p) => ({ ...p, atiende: atiendeElDia(p.id) }))
    .sort((a, b) => Number(a.atiende === false) - Number(b.atiende === false)),
);
// Si el elegido no atiende en la sucursal nueva, el primero que sí.
watch(profesionalesCompatibles, (lista) => {
  if (
    lista.length > 0 &&
    !lista.some((p) => p.id === form.value.instructorId)
  ) {
    form.value.instructorId = lista[0].id;
  }
});

// ---- Horarios libres de ese profesional ese día (los calcula el servidor) ----
interface HuecoLibre {
  inicia_local: string;
}
const horasLibres = ref<string[]>([]);
const cargandoHoras = ref(false);
const errorHoras = ref(false);
let horaPedida = "";
let consulta = 0;
async function cargarHoras(): Promise<void> {
  const f = form.value;
  if (
    !props.abierto ||
    f.ofertaId === "" ||
    f.instructorId === "" ||
    f.sucursalId === "" ||
    f.fecha === ""
  ) {
    horasLibres.value = [];
    return;
  }
  const esta = ++consulta;
  cargandoHoras.value = true;
  errorHoras.value = false;
  try {
    const { data } = await api.get<{ data: { slots: HuecoLibre[] } }>(
      `${props.base}/disponibilidad`,
      {
        params: {
          instructor_id: f.instructorId,
          sucursal_id: f.sucursalId,
          fecha: f.fecha,
          oferta_id: f.ofertaId,
          // Sin duración en el servicio, la que se capture.
          ...(oferta.value?.duracion_minutos
            ? {}
            : { duracion_minutos: Number(f.duracion) || 30 }),
        },
      },
    );
    if (esta !== consulta) {
      return;
    }
    horasLibres.value = data.data.slots.map((s) =>
      s.inicia_local.slice(11, 16),
    );
  } catch {
    if (esta === consulta) {
      horasLibres.value = [];
      errorHoras.value = true;
    }
  } finally {
    if (esta === consulta) {
      cargandoHoras.value = false;
      ajustarHora();
    }
  }
}
// Tras consultar: la hora pedida (la que se tocó en la agenda) se queda si está libre;
// si no, quien puede la captura a mano (con su aviso) y los demás eligen una libre.
function ajustarHora(): void {
  if (manual.value) {
    return;
  }
  const pedida = horaPedida || form.value.hora;
  horaPedida = "";
  if (pedida !== "" && horasLibres.value.includes(pedida)) {
    form.value.hora = pedida;
    return;
  }
  if (pedida !== "" && puedeManual.value && !errorHoras.value) {
    form.value.hora = pedida;
    manual.value = true;
    return;
  }
  form.value.hora = "";
}
watch(
  () => [
    props.abierto,
    form.value.ofertaId,
    form.value.instructorId,
    form.value.sucursalId,
    form.value.fecha,
    oferta.value?.duracion_minutos ? 0 : form.value.duracion,
  ],
  () => void cargarHoras(),
);
function elegirHora(hora: string): void {
  manual.value = false;
  form.value.hora = hora;
}
function alternarManual(): void {
  manual.value = !manual.value;
  if (!manual.value && !horasLibres.value.includes(form.value.hora)) {
    form.value.hora = "";
  }
}
const nombreProfesional = computed(
  () =>
    props.profesionales.find((p) => p.id === form.value.instructorId)?.nombre ??
    "",
);

// La duración sigue al servicio elegido.
watch(
  () => form.value.ofertaId,
  () => {
    form.value.duracion = oferta.value?.duracion_minutos ?? form.value.duracion;
  },
);

// En la moneda y con los números del país del negocio; los centavos, si los hay.
function dinero(minor: number): string {
  const s = useSesionTenantStore();
  return dineroDelPais(minor, s.moneda, s.pais, {
    minimumFractionDigits: minor % 100 === 0 ? 0 : 2,
  });
}
const aviso = computed(() => {
  const o = oferta.value;
  if (o === undefined) {
    return "";
  }
  return o.politica_reserva === "pago"
    ? t("agendaVisual.nuevaCita.seCobraEnCaja", {
        monto: dinero(o.precio_clase_minor ?? 0),
      })
    : t("agendaVisual.nuevaCita.consumeCredito");
});

function aMinutos(hhmm: string): number {
  const [h, m] = hhmm.split(":").map(Number);
  return (h ?? 0) * 60 + (m ?? 0);
}
const avisoHorario = computed<{ texto: string; grave: boolean } | null>(() => {
  const f = form.value;
  // Una hora libre ya está dentro de su atención y sin choques.
  if (
    f.fecha === "" ||
    f.hora === "" ||
    f.instructorId === "" ||
    !manual.value
  ) {
    return null;
  }
  const ini = aMinutos(f.hora);
  const fin = ini + Math.max(Number(f.duracion) || 0, 1);
  const zona = props.zona ?? useSesionTenantStore().zonaHoraria;
  const bloqueo = (props.bloqueos ?? []).find((b) => {
    const aplica =
      (b.ambito === "profesional" && b.instructor_id === f.instructorId) ||
      (b.ambito === "sede" && b.sucursal_id === f.sucursalId);
    const diaIni = fechaLocal(b.desde, zona);
    const diaFin = fechaLocal(b.hasta, zona);
    if (!aplica || f.fecha < diaIni || f.fecha > diaFin) {
      return false;
    }
    const desde = diaIni < f.fecha ? 0 : minutosLocal(b.desde, zona);
    const hasta = diaFin > f.fecha ? 24 * 60 : minutosLocal(b.hasta, zona);
    return ini < hasta && fin > desde;
  });
  if (bloqueo !== undefined) {
    return {
      texto: t("agendaVisual.nuevaCita.conBloqueo", { motivo: bloqueo.motivo }),
      grave: true,
    };
  }
  // Sin horario definido para esa persona en esa sede, no hay con qué comparar.
  const suyas = (props.ventanas ?? []).filter(
    (v) =>
      v.instructor_id === f.instructorId &&
      (v.sucursal_id === null || v.sucursal_id === f.sucursalId),
  );
  if (suyas.length === 0) {
    return null;
  }
  const profesional =
    props.profesionales.find((p) => p.id === f.instructorId)?.nombre ?? "";
  const delDia = suyas.filter((v) => v.dia_semana === diaIso(f.fecha));
  if (delDia.length === 0) {
    return {
      texto: t("agendaVisual.nuevaCita.noAtiende", { profesional }),
      grave: false,
    };
  }
  const cabe = delDia.some(
    (v) => aMinutos(v.hora_inicio) <= ini && fin <= aMinutos(v.hora_fin),
  );
  return cabe
    ? horasLibres.value.includes(f.hora)
      ? null
      : {
          texto: t("agendaVisual.nuevaCita.fueraDeLibres"),
          grave: false,
        }
    : {
        texto: t("agendaVisual.nuevaCita.fueraDeHorario", { profesional }),
        grave: false,
      };
});

// Según el modo elegido: el alumno buscado o el nombre del que se da de alta.
const listo = computed(
  () =>
    (nuevo.value
      ? nuevoCliente.value.nombre.trim() !== ""
      : form.value.clienteId !== "") &&
    form.value.ofertaId !== "" &&
    form.value.instructorId !== "" &&
    form.value.sucursalId !== "" &&
    form.value.fecha !== "" &&
    form.value.hora !== "",
);

// Una cita por intento: reintentar lo mismo no agenda otra (ADR 0201).
const intento = intentoUnico();

async function agendar(): Promise<void> {
  guardando.value = true;
  error.value = null;
  try {
    let personaId = form.value.clienteId;
    let nombre = clienteSel.value?.nombre ?? "";
    if (nuevo.value) {
      const { data } = await api.post<{ data: { id: string } }>(
        `${props.base}/miembros`,
        {
          nombre: nuevoCliente.value.nombre.trim(),
          celular: nuevoCliente.value.celular.trim() || null,
          ...(ofrecerWhatsApp.value && nuevoCliente.value.whatsapp
            ? { acepta_whatsapp: true }
            : {}),
          tipo: "miembro",
          sucursal_id: form.value.sucursalId,
        },
      );
      personaId = data.data.id;
      nombre = nuevoCliente.value.nombre.trim();
      // Ya existe: si la cita no se puede agendar (hueco ocupado), al reintentar se
      // usa este cliente y no se crea otro igual.
      clienteSel.value = {
        id: personaId,
        nombre,
        detalle: nuevoCliente.value.celular.trim() || null,
      };
      form.value.clienteId = personaId;
      nuevo.value = false;
    }
    const pedido = {
      persona_id: personaId,
      oferta_id: form.value.ofertaId,
      sucursal_id: form.value.sucursalId,
      instructor_id: form.value.instructorId,
      inicia_en_local: `${form.value.fecha} ${form.value.hora}:00`,
      duracion_minutos: Number(form.value.duracion),
    };
    // Reintentar lo mismo (sin red, doble clic) devuelve la cita ya agendada (ADR 0201).
    await api.post(`${props.base}/agenda/citas`, {
      ...pedido,
      idempotency_key: intento.clave(pedido),
    });
    intento.listo();
    toast.exito(
      t("agendaVisual.nuevaCita.agendada", {
        cliente: nombre,
        hora: form.value.hora,
      }),
    );
    descartar.fijar();
    emit("agendada");
  } catch (e) {
    error.value = mensajeDeError(e);
    toast.error(error.value);
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <PanelLateral
    :abierto="abierto"
    :titulo="$t('agendaVisual.nuevaCita.titulo')"
    :ocupado="guardando"
    @cerrar="cerrar"
  >
    <form id="form-nueva-cita" class="space-y-4 p-5" @submit.prevent="agendar">
      <!-- Cliente: buscar o dar de alta al vuelo (dos modos del mismo campo). -->
      <div>
        <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
          <span class="tu-label mb-0">{{
            $t("agendaVisual.nuevaCita.cliente")
          }}</span>
          <div
            v-if="puedeCrearCliente"
            class="tu-segmentado"
            role="group"
            :aria-label="$t('agendaVisual.nuevaCita.cliente')"
            data-prueba="modo-cliente"
          >
            <button type="button" :aria-pressed="!nuevo" @click="nuevo = false">
              {{ $t("agendaVisual.nuevaCita.existente") }}
            </button>
            <button type="button" :aria-pressed="nuevo" @click="nuevo = true">
              {{ $t("agendaVisual.nuevaCita.nuevoCliente") }}
            </button>
          </div>
        </div>
        <BuscarPersona
          v-if="!nuevo"
          v-model="form.clienteId"
          :personas="clienteSel ? [clienteSel] : []"
          :buscar-en="`${base}/miembros`"
          :parametros="{ tipo: 'miembro' }"
          :placeholder="$t('agendaVisual.nuevaCita.buscarCliente')"
          data-prueba="buscar-cliente"
          @elegir="clienteSel = $event"
        />
        <div v-else class="space-y-2 tu-card p-3">
          <label class="block">
            <span class="tu-label">{{
              $t("agendaVisual.nuevaCita.nombre")
            }}</span>
            <input v-model="nuevoCliente.nombre" class="tu-input" required />
          </label>
          <div>
            <label class="tu-label" for="pnc-celular">{{
              $t("agendaVisual.nuevaCita.celular")
            }}</label>
            <!-- Con su lada: la del negocio si no se elige otra (ADR 0103). -->
            <CampoCelular
              id="pnc-celular"
              v-model="nuevoCliente.celular"
              maxlength="30"
              autocomplete="off"
            />
          </div>
          <label v-if="ofrecerWhatsApp" class="flex items-start gap-2 text-sm">
            <input
              v-model="nuevoCliente.whatsapp"
              type="checkbox"
              class="mt-1"
              data-prueba="acepta-whatsapp"
            />
            <span>
              {{ $t("avisosWhatsApp.aceptaCliente") }}
              <span
                class="block text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ $t("avisosWhatsApp.ayudaCliente") }}</span
              >
            </span>
          </label>
        </div>
      </div>

      <!-- 1. La sucursal (si hay varias): de ella salen quienes atienden. -->
      <label v-if="sucursales.length > 1" class="block">
        <span class="tu-label">{{
          $t("agendaVisual.nuevaCita.sucursal")
        }}</span>
        <select v-model="form.sucursalId" class="tu-input" required>
          <option v-for="s in sucursales" :key="s.id" :value="s.id">
            {{ s.nombre }}
          </option>
        </select>
      </label>
      <LeyendaSucursal v-else />

      <!-- 2. El servicio: su duración y sus márgenes cuentan para los libres. -->
      <label class="block">
        <span class="tu-label">{{
          $t("agendaVisual.nuevaCita.servicio")
        }}</span>
        <select v-model="form.ofertaId" class="tu-input" required>
          <option v-for="o in ofertas" :key="o.id" :value="o.id">
            {{ o.nombre
            }}{{ o.duracion_minutos ? ` · ${o.duracion_minutos} min` : ""
            }}{{
              o.politica_reserva === "pago" && o.precio_clase_minor
                ? ` · ${dinero(o.precio_clase_minor)}`
                : ""
            }}
          </option>
        </select>
      </label>

      <!-- 3. El día y quién atiende (los de la sucursal; primero quien trabaja ese
           día). Con una sola persona, no hay a quién elegir (M08). -->
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block">
          <span class="tu-label">{{ $t("agendaVisual.nuevaCita.fecha") }}</span>
          <input v-model="form.fecha" class="tu-input" type="date" required />
        </label>
        <label v-if="profesionales.length > 1" class="block">
          <span class="tu-label">{{
            $t("agendaVisual.nuevaCita.profesional")
          }}</span>
          <select
            v-model="form.instructorId"
            class="tu-input"
            required
            data-prueba="profesional-cita"
          >
            <option
              v-for="p in profesionalesCompatibles"
              :key="p.id"
              :value="p.id"
            >
              {{
                p.atiende === false
                  ? $t("agendaVisual.nuevaCita.noAtiendeDia", {
                      nombre: p.nombre,
                    })
                  : p.nombre
              }}
            </option>
          </select>
        </label>
      </div>

      <!-- 4. Los horarios libres de esa persona ese día (los calcula el servidor). -->
      <fieldset data-prueba="horarios-libres">
        <legend class="tu-label">
          {{ $t("agendaVisual.nuevaCita.horariosLibres") }}
        </legend>
        <p
          v-if="cargandoHoras"
          class="text-sm"
          role="status"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("agendaVisual.nuevaCita.buscandoHorarios") }}
        </p>
        <p
          v-else-if="errorHoras"
          class="flex flex-wrap items-center gap-2 text-sm"
          role="alert"
          style="color: var(--error)"
        >
          {{ $t("agendaVisual.nuevaCita.errorHorarios") }}
          <button
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            @click="cargarHoras"
          >
            {{ $t("comun.reintentar") }}
          </button>
        </p>
        <p
          v-else-if="horasLibres.length === 0"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="sin-horarios"
        >
          {{
            $t("agendaVisual.nuevaCita.sinHorarios", {
              profesional: nombreProfesional,
            })
          }}
        </p>
        <div v-else class="flex flex-wrap gap-2">
          <button
            v-for="h in horasLibres"
            :key="h"
            type="button"
            class="tu-btn tu-btn-chico tabular-nums"
            :class="
              !manual && form.hora === h
                ? 'tu-btn-primario'
                : 'tu-btn-secundario'
            "
            :aria-pressed="!manual && form.hora === h"
            :data-hora="h"
            @click="elegirHora(h)"
          >
            {{ h }}
          </button>
        </div>
        <button
          v-if="puedeManual"
          type="button"
          class="tu-enlace mt-2 text-sm"
          data-prueba="otra-hora"
          @click="alternarManual"
        >
          {{
            manual
              ? $t("agendaVisual.nuevaCita.volverALibres")
              : $t("agendaVisual.nuevaCita.otraHora")
          }}
        </button>
      </fieldset>

      <!-- Lo excepcional: otra hora a mano (quien gestiona la agenda), y los minutos
           si el servicio no los fija. -->
      <div
        v-if="manual || !oferta?.duracion_minutos"
        class="grid grid-cols-2 gap-3"
      >
        <label v-if="manual" class="block">
          <span class="tu-label">{{ $t("agendaVisual.nuevaCita.hora") }}</span>
          <input
            v-model="form.hora"
            class="tu-input"
            type="time"
            step="900"
            required
            data-prueba="hora-manual"
          />
        </label>
        <label class="block">
          <span class="tu-label">{{
            $t("agendaVisual.nuevaCita.duracion")
          }}</span>
          <!-- Con duración en el servicio, esa manda (el servidor la usa): solo se lee. -->
          <input
            v-model.number="form.duracion"
            class="tu-input"
            type="number"
            min="5"
            step="5"
            required
            :readonly="Boolean(oferta?.duracion_minutos)"
            :aria-describedby="
              oferta?.duracion_minutos ? 'nc-duracion-servicio' : undefined
            "
            data-prueba="duracion-cita"
          />
          <span
            v-if="oferta?.duracion_minutos"
            id="nc-duracion-servicio"
            class="tu-hint mt-1 block"
            >{{ $t("agendaVisual.nuevaCita.duracionDelServicio") }}</span
          >
        </label>
      </div>

      <p
        v-if="avisoHorario"
        class="flex items-start gap-2 text-sm"
        :style="{ color: avisoHorario.grave ? 'var(--error)' : 'var(--aviso)' }"
        role="status"
        data-prueba="aviso-horario"
      >
        <span
          class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
          :style="{ background: 'currentColor' }"
          aria-hidden="true"
        ></span>
        {{ avisoHorario.texto }}
      </p>
      <p
        v-if="aviso !== ''"
        class="text-sm rounded-lg px-3 py-2"
        :style="{ background: 'var(--superficie-2)' }"
      >
        {{ aviso }}
      </p>
      <AvisoTono v-if="error" tono="peligro" role="alert">
        {{ error }}
      </AvisoTono>
    </form>

    <template #pie>
      <button
        type="button"
        class="tu-btn tu-btn-secundario"
        :disabled="guardando"
        @click="cerrar"
      >
        {{ $t("comun.cancelar") }}
      </button>
      <button
        type="submit"
        form="form-nueva-cita"
        class="tu-btn tu-btn-primario"
        :disabled="!listo || guardando"
        :aria-busy="guardando || undefined"
      >
        {{
          guardando
            ? $t("agendaVisual.nuevaCita.agendando")
            : $t("agendaVisual.nuevaCita.agendar")
        }}
      </button>
    </template>
  </PanelLateral>
</template>
