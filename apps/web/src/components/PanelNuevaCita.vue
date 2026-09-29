<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Alta de una CITA por el negocio (recepción, teléfono, mostrador): cliente (se busca
 * o se da de alta al vuelo), servicio, profesional y hora. La cita queda confirmada;
 * si el servicio es de pago, se cobra en caja.
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
  profesionales: { id: string; nombre: string }[];
  clientes: { id: string; nombre: string; nombre_completo: string }[];
  inicial: {
    fecha: string;
    hora: string;
    instructorId: string | null;
    sucursalId: string | null;
  };
}>();

const emit = defineEmits<{ cerrar: []; agendada: [] }>();

const { t } = useI18n();
const toast = useToastStore();

const form = ref({
  clienteId: "",
  ofertaId: "",
  instructorId: "",
  sucursalId: "",
  fecha: "",
  hora: "",
  duracion: 30,
});
const busqueda = ref("");
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
    busqueda.value = "";
    nuevo.value = false;
    nuevoCliente.value = { nombre: "", celular: "", whatsapp: false };
    error.value = null;
  },
);

const oferta = computed(() =>
  props.ofertas.find((o) => o.id === form.value.ofertaId),
);
// La duración sigue al servicio elegido.
watch(
  () => form.value.ofertaId,
  () => {
    form.value.duracion = oferta.value?.duracion_minutos ?? form.value.duracion;
  },
);

function nombreCliente(c: { nombre: string; nombre_completo: string }): string {
  return c.nombre_completo || c.nombre;
}
const coincidencias = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  if (q === "") {
    return [];
  }
  return props.clientes
    .filter((c) => nombreCliente(c).toLowerCase().includes(q))
    .slice(0, 6);
});
const clienteSel = computed(() =>
  props.clientes.find((c) => c.id === form.value.clienteId),
);
function elegir(id: string): void {
  form.value.clienteId = id;
  busqueda.value = "";
}

function dinero(minor: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
    maximumFractionDigits: 0,
  }).format(minor / 100);
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

const listo = computed(
  () =>
    (form.value.clienteId !== "" ||
      (nuevo.value && nuevoCliente.value.nombre.trim() !== "")) &&
    form.value.ofertaId !== "" &&
    form.value.instructorId !== "" &&
    form.value.sucursalId !== "" &&
    form.value.fecha !== "" &&
    form.value.hora !== "",
);

async function agendar(): Promise<void> {
  guardando.value = true;
  error.value = null;
  try {
    let personaId = form.value.clienteId;
    let nombre = clienteSel.value ? nombreCliente(clienteSel.value) : "";
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
    }
    await api.post(`${props.base}/agenda/citas`, {
      persona_id: personaId,
      oferta_id: form.value.ofertaId,
      sucursal_id: form.value.sucursalId,
      instructor_id: form.value.instructorId,
      inicia_en_local: `${form.value.fecha} ${form.value.hora}:00`,
      duracion_minutos: Number(form.value.duracion),
    });
    toast.exito(
      t("agendaVisual.nuevaCita.agendada", {
        cliente: nombre,
        hora: form.value.hora,
      }),
    );
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
    @cerrar="emit('cerrar')"
  >
    <form class="space-y-4 p-5" @submit.prevent="agendar">
      <!-- Cliente: buscar o dar de alta al vuelo -->
      <div>
        <span class="tu-label">{{ $t("agendaVisual.nuevaCita.cliente") }}</span>
        <div
          v-if="clienteSel && !nuevo"
          class="flex items-center justify-between gap-2 tu-card px-3 py-2"
        >
          <span class="font-semibold truncate">{{
            nombreCliente(clienteSel)
          }}</span>
          <button
            type="button"
            class="tu-enlace text-sm"
            @click="form.clienteId = ''"
          >
            {{ $t("agendaVisual.nuevaCita.cambiar") }}
          </button>
        </div>
        <template v-else-if="!nuevo">
          <input
            v-model="busqueda"
            class="tu-input"
            type="search"
            :placeholder="$t('agendaVisual.nuevaCita.buscarCliente')"
            :aria-label="$t('agendaVisual.nuevaCita.buscarCliente')"
          />
          <ul
            v-if="coincidencias.length > 0"
            class="mt-1 tu-card divide-y divide-[var(--borde)]"
          >
            <li v-for="c in coincidencias" :key="c.id">
              <button
                type="button"
                class="w-full text-left px-3 py-2 text-sm hover:opacity-80"
                @click="elegir(c.id)"
              >
                {{ nombreCliente(c) }}
              </button>
            </li>
          </ul>
          <button
            v-if="puedeCrearCliente"
            type="button"
            class="tu-enlace text-sm mt-2"
            @click="nuevo = true"
          >
            {{ $t("agendaVisual.nuevaCita.nuevoCliente") }}
          </button>
        </template>
        <div v-else class="space-y-2 tu-card p-3">
          <label class="block">
            <span class="tu-label">{{
              $t("agendaVisual.nuevaCita.nombre")
            }}</span>
            <input v-model="nuevoCliente.nombre" class="tu-input" required />
          </label>
          <label class="block">
            <span class="tu-label">{{
              $t("agendaVisual.nuevaCita.celular")
            }}</span>
            <input
              v-model="nuevoCliente.celular"
              class="tu-input"
              type="tel"
              inputmode="tel"
            />
          </label>
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
          <button
            type="button"
            class="tu-enlace text-sm"
            @click="nuevo = false"
          >
            {{ $t("agendaVisual.nuevaCita.buscarExistente") }}
          </button>
        </div>
      </div>

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

      <label class="block">
        <span class="tu-label">{{
          $t("agendaVisual.nuevaCita.profesional")
        }}</span>
        <select v-model="form.instructorId" class="tu-input" required>
          <option v-for="p in profesionales" :key="p.id" :value="p.id">
            {{ p.nombre }}
          </option>
        </select>
      </label>

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

      <div class="grid grid-cols-3 gap-3">
        <label class="block col-span-3 sm:col-span-1">
          <span class="tu-label">{{ $t("agendaVisual.nuevaCita.fecha") }}</span>
          <input v-model="form.fecha" class="tu-input" type="date" required />
        </label>
        <label class="block">
          <span class="tu-label">{{ $t("agendaVisual.nuevaCita.hora") }}</span>
          <input
            v-model="form.hora"
            class="tu-input"
            type="time"
            step="900"
            required
          />
        </label>
        <label class="block">
          <span class="tu-label">{{
            $t("agendaVisual.nuevaCita.duracion")
          }}</span>
          <input
            v-model.number="form.duracion"
            class="tu-input"
            type="number"
            min="5"
            step="5"
            required
          />
        </label>
      </div>

      <p
        v-if="aviso !== ''"
        class="text-sm rounded-lg px-3 py-2"
        :style="{ background: 'var(--superficie-2)' }"
      >
        {{ aviso }}
      </p>
      <p v-if="error" class="text-sm" style="color: var(--error)">
        {{ error }}
      </p>
    </form>

    <template #pie>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        @click="emit('cerrar')"
      >
        {{ $t("comun.cancelar") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-primario"
        :disabled="!listo || guardando"
        @click="agendar"
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
