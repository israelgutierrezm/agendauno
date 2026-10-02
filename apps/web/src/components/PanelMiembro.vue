<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import MarcoDetalle from "@/components/MarcoDetalle.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Resumen {
  id: string;
  nombre_completo: string;
  email: string | null;
  // Cómo conoció al negocio (lo dijo al agendar en línea).
  como_nos_conocio?: string | null;
  // Avisos por WhatsApp (ADR 0069): solo si el negocio los usa.
  whatsapp?: { disponible: boolean; acepta: boolean; con_celular: boolean };
  tipo: string;
  activo: boolean;
  asistencias: number;
  primera_vez: boolean;
  saldo_creditos: number;
  saldo_unidades: number;
  membresia: {
    estado: string;
    valido_hasta: string | null;
    pausada_hasta?: string | null;
  };
  adeudo: boolean;
  documentos_pendientes: number;
  proxima_reserva: {
    clase: string | null;
    inicia_en: string;
    zona_horaria: string | null;
  } | null;
  alertas: string[];
}

// `incrustado`: se pinta junto a la lista (escritorio); si no, como panel (móvil).
const props = defineProps<{
  personaId: string;
  nombre: string;
  incrustado?: boolean;
  // Sede donde se registra la entrada (la que se está atendiendo en recepción).
  sucursalId?: string;
  // Desde la ficha: abre directo en la venta y no repite los enlaces a la ficha.
  venta?: boolean;
}>();
const emit = defineEmits<{ (e: "cerrar"): void }>();

const { t } = useI18n();
const sesionStore = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesionStore.slug}`);
// Vender también lista los productos: hacen falta los dos permisos.
const puedeVender = computed(
  () =>
    sesionStore.puede("ordenes.gestionar") &&
    sesionStore.puede("productos.ver"),
);
const puedeRegistrarEntrada = computed(() =>
  sesionStore.puede("checkins.registrar"),
);
const puedeEditar = computed(() => sesionStore.puede("miembros.gestionar"));

// El cliente pidió (o ya no quiere) los avisos por WhatsApp: lo marca recepción.
const guardandoWhatsApp = ref(false);
async function cambiarWhatsApp(acepta: boolean): Promise<void> {
  guardandoWhatsApp.value = true;
  error.value = null;
  try {
    const { data } = await api.put<{ data: { acepta_whatsapp: boolean } }>(
      `${base.value}/miembros/${props.personaId}`,
      { acepta_whatsapp: acepta },
    );
    if (resumen.value?.whatsapp) {
      resumen.value.whatsapp.acepta = data.data.acepta_whatsapp;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardandoWhatsApp.value = false;
  }
}

// Control de acceso: la entrada se permite por reserva vigente o acceso libre.
const entrada = ref<{ permitido: boolean; codigo: string } | null>(null);
const registrandoEntrada = ref(false);
async function registrarEntrada(): Promise<void> {
  registrandoEntrada.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{
      data: { permitido: boolean; codigo: string };
    }>(`${base.value}/accesos`, {
      persona_id: props.personaId,
      metodo: "manual",
      sucursal_id: props.sucursalId || null,
    });
    entrada.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    registrandoEntrada.value = false;
  }
}

const resumen = ref<Resumen | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

// Venta rápida + cobro en ventanilla: crea la orden y la liquida (fulfillment).
interface Producto {
  id: string;
  nombre: string;
  precio_minor: number;
  moneda: string;
}
const vendiendo = ref(false);
const productos = ref<Producto[]>([]);
const productoSel = ref("");
const metodo = ref("efectivo");
const procesando = ref(false);
const avisoVenta = ref<string | null>(null);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

async function abrirVenta(): Promise<void> {
  vendiendo.value = true;
  avisoVenta.value = null;
  if (productos.value.length === 0) {
    try {
      const { data } = await api.get<{ data: Producto[] }>(
        `${base.value}/productos`,
      );
      productos.value = data.data;
    } catch (e) {
      error.value = mensajeDeError(e);
    }
  }
}

async function vender(): Promise<void> {
  if (productoSel.value === "") {
    return;
  }
  // Vender cobra en el momento: se confirma qué, cuánto, cómo y a quién.
  const producto = productos.value.find((p) => p.id === productoSel.value);
  if (
    !(await confirmar(
      t("confirmaciones.venta", {
        producto: producto?.nombre ?? "",
        monto: producto ? dinero(producto.precio_minor, producto.moneda) : "",
        metodo: t(`recepcion.miembro.${metodo.value}`),
        persona: resumen.value?.nombre_completo ?? props.nombre,
      }),
      { aceptar: t("confirmaciones.cobrar") },
    ))
  ) {
    return;
  }
  procesando.value = true;
  error.value = null;
  avisoVenta.value = null;
  try {
    const { data: orden } = await api.post<{ data: { id: string } }>(
      `${base.value}/ordenes`,
      {
        comprador_id: props.personaId,
        items: [{ producto_id: productoSel.value, cantidad: 1 }],
      },
    );
    await api.post(`${base.value}/ordenes/${orden.data.id}/liquidar`, {
      metodo: metodo.value,
    });
    avisoVenta.value = t("recepcion.miembro.vendido");
    vendiendo.value = false;
    productoSel.value = "";
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    procesando.value = false;
  }
}

/** "Membresía vigente · hasta 12 oct" (o "Sin membresía"), para la píldora. */
const pastilla = computed<{ texto: string; aviso: boolean } | null>(() => {
  const m = resumen.value?.membresia;
  if (!m) {
    return null;
  }
  const estado = t(`recepcion.membresia.${m.estado}`);
  const texto =
    m.estado === "sin"
      ? estado
      : `${t("recepcion.miembro.membresia")} ${estado.toLowerCase()}`;
  // En pausa, la fecha que importa es cuándo vuelve.
  const limite = m.estado === "pausada" ? m.pausada_hasta : m.valido_hasta;
  const hasta = limite
    ? new Intl.DateTimeFormat("es-MX", {
        day: "numeric",
        month: "short",
      }).format(new Date(`${limite.slice(0, 10)}T12:00:00`))
    : null;
  return {
    texto: hasta !== null ? `${texto} · hasta ${hasta}` : texto,
    aviso:
      m.estado === "por_vencer" ||
      m.estado === "vencida" ||
      m.estado === "pausada",
  };
});

// Ámbar para "por vencer"; rojo para el resto de alertas.
function colorAlerta(codigo: string): string {
  return codigo === "membresia_por_vencer" || codigo === "membresia_pausada"
    ? "var(--aviso)"
    : "var(--error)";
}

function fecha(iso: string, zona: string | null): string {
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: zona ?? undefined,
  }).format(new Date(iso));
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Resumen }>(
      `${base.value}/miembros/${props.personaId}/resumen`,
    );
    resumen.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

watch(
  () => props.personaId,
  () => {
    entrada.value = null;
    void cargar();
  },
  { immediate: true },
);
if (props.venta && puedeVender.value) {
  void abrirVenta();
}

// Cancelar la venta: desde la ficha cierra el panel (se abrió solo para vender).
function cancelarVenta(): void {
  if (props.venta) {
    emit("cerrar");
    return;
  }
  vendiendo.value = false;
}
</script>

<template>
  <MarcoDetalle
    :incrustado="incrustado"
    :etiqueta="sesionStore.terminologia.miembro"
    :titulo="resumen?.nombre_completo ?? nombre"
    :subtitulo="resumen?.email ?? undefined"
    :etiqueta-cerrar="$t('recepcion.panel.cerrar')"
    @cerrar="emit('cerrar')"
  >
    <template #destacado>
      <span
        v-if="pastilla"
        class="md-pastilla"
        :class="{ 'md-pastilla-aviso': pastilla.aviso }"
        >{{ pastilla.texto }}</span
      >
      <!-- Alertas en una línea: punto + texto -->
      <p v-if="resumen" class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-sm">
        <span
          v-if="resumen.alertas.length === 0"
          class="tu-badge tu-badge-exito"
          >{{ $t("recepcion.miembro.sinAlertas") }}</span
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
    </template>

    <p v-if="error" class="mb-3 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p v-if="cargando" class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

    <template v-else-if="resumen">
      <!-- Datos operativos -->
      <dl
        class="rounded-xl border px-4 text-sm divide-y divide-[var(--borde)]"
        :style="{
          background: 'var(--superficie)',
          borderColor: 'var(--borde)',
        }"
      >
        <div class="flex items-center justify-between gap-3 py-2.5">
          <dt :style="{ color: 'var(--texto-suave)' }">
            {{ $t("recepcion.miembro.saldo") }}
          </dt>
          <dd class="text-right font-medium">
            {{
              $t(
                "recepcion.miembro.creditos",
                { n: resumen.saldo_creditos },
                resumen.saldo_creditos === 1 ? 1 : 2,
              )
            }}
          </dd>
        </div>
        <div class="flex items-center justify-between gap-3 py-2.5">
          <dt :style="{ color: 'var(--texto-suave)' }">
            {{ $t("recepcion.miembro.proxima") }}
          </dt>
          <dd class="text-right font-medium">
            <template v-if="resumen.proxima_reserva">
              {{ resumen.proxima_reserva.clase ?? "—" }}
              <span :style="{ color: 'var(--texto-suave)' }">
                ·
                {{
                  fecha(
                    resumen.proxima_reserva.inicia_en,
                    resumen.proxima_reserva.zona_horaria,
                  )
                }}</span
              >
            </template>
            <span v-else :style="{ color: 'var(--texto-suave)' }">{{
              $t("recepcion.miembro.sinProxima")
            }}</span>
          </dd>
        </div>
        <div class="flex items-center justify-between gap-3 py-2.5">
          <dt :style="{ color: 'var(--texto-suave)' }">
            {{ $t("recepcion.miembro.asistencias") }}
          </dt>
          <dd class="text-right font-medium">
            {{ resumen.asistencias }}
            <span
              v-if="resumen.primera_vez"
              class="ml-1 text-xs font-medium"
              :style="{ color: 'var(--aviso)' }"
              >{{ $t("agenda.roster.primeraVez") }}</span
            >
          </dd>
        </div>
        <div
          v-if="resumen.como_nos_conocio"
          class="flex items-center justify-between gap-3 py-2.5"
          data-prueba="como-nos-conocio"
        >
          <dt :style="{ color: 'var(--texto-suave)' }">
            {{ $t("perfilPublico.agendar.nosConocioPor") }}
          </dt>
          <dd class="text-right font-medium">
            {{ $t(`perfilPublico.origenes.${resumen.como_nos_conocio}`) }}
          </dd>
        </div>
        <div
          v-if="resumen.whatsapp?.disponible"
          class="flex items-center justify-between gap-3 py-2.5"
          data-prueba="whatsapp"
        >
          <dt :style="{ color: 'var(--texto-suave)' }">
            {{ $t("avisosWhatsApp.fila") }}
          </dt>
          <dd class="text-right">
            <label
              v-if="resumen.whatsapp.con_celular"
              class="inline-flex items-center gap-2"
              :title="$t('avisosWhatsApp.ayudaCliente')"
            >
              <input
                type="checkbox"
                data-prueba="acepta-whatsapp"
                :checked="resumen.whatsapp.acepta"
                :disabled="guardandoWhatsApp || !puedeEditar"
                @change="
                  cambiarWhatsApp(($event.target as HTMLInputElement).checked)
                "
              />
              {{ $t("avisosWhatsApp.acepta") }}
            </label>
            <span v-else :style="{ color: 'var(--texto-suave)' }">{{
              $t("avisosWhatsApp.sinCelular")
            }}</span>
          </dd>
        </div>
        <div
          v-if="resumen.documentos_pendientes > 0"
          class="flex items-center justify-between gap-3 py-2.5"
        >
          <dt :style="{ color: 'var(--texto-suave)' }">
            {{ $t("recepcion.miembro.documentos") }}
          </dt>
          <dd class="text-right font-medium" :style="{ color: 'var(--aviso)' }">
            {{ resumen.documentos_pendientes }}
          </dd>
        </div>
      </dl>

      <p v-if="!venta" class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-sm">
        <RouterLink
          :to="{ name: 'ficha-miembro', params: { id: personaId } }"
          class="tu-enlace"
          @click="emit('cerrar')"
          >{{ $t("recepcion.miembro.verFicha") }} →</RouterLink
        >
        <RouterLink
          :to="{
            name: 'ficha-miembro',
            params: { id: personaId },
            query: { seccion: 'expediente' },
          }"
          class="tu-enlace"
          @click="emit('cerrar')"
          >{{ $t("expediente.titulo") }} →</RouterLink
        >
      </p>

      <!-- Entrada (control de acceso) -->
      <div
        v-if="puedeRegistrarEntrada"
        class="mt-5 border-t pt-4"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <button
          type="button"
          class="tu-btn tu-btn-fantasma w-full text-sm"
          :disabled="registrandoEntrada"
          @click="registrarEntrada"
        >
          {{ $t("accesoRecepcion.registrar") }}
        </button>
        <p
          v-if="entrada"
          class="mt-2 text-sm"
          role="status"
          :style="{
            color: entrada.permitido ? 'var(--exito)' : 'var(--error)',
          }"
        >
          <span class="font-medium">{{
            entrada.permitido
              ? $t("accesoRecepcion.permitido")
              : $t("accesoRecepcion.denegado")
          }}</span>
          · {{ $t(`accesoRecepcion.codigos.${entrada.codigo}`) }}
        </p>
      </div>

      <!-- Venta rápida + cobro en ventanilla -->
      <div
        v-if="puedeVender"
        class="mt-5 border-t pt-4"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <button
          v-if="!vendiendo"
          type="button"
          class="tu-btn tu-btn-primario w-full text-sm"
          @click="abrirVenta"
        >
          {{ $t("recepcion.miembro.vender") }}
        </button>
        <div v-else class="space-y-2">
          <select v-model="productoSel" class="tu-input">
            <option value="" disabled>
              {{ $t("recepcion.miembro.elegirProducto") }}
            </option>
            <option v-for="p in productos" :key="p.id" :value="p.id">
              {{ p.nombre }} — {{ dinero(p.precio_minor, p.moneda) }}
            </option>
          </select>
          <select v-model="metodo" class="tu-input">
            <option value="efectivo">
              {{ $t("recepcion.miembro.efectivo") }}
            </option>
            <option value="transferencia">
              {{ $t("recepcion.miembro.transferencia") }}
            </option>
          </select>
          <div class="flex gap-2">
            <button
              type="button"
              class="tu-btn tu-btn-primario flex-1 text-sm"
              :disabled="procesando || productoSel === ''"
              @click="vender"
            >
              {{
                procesando
                  ? $t("recepcion.miembro.cobrando")
                  : $t("recepcion.miembro.cobrar")
              }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              :disabled="procesando"
              @click="cancelarVenta"
            >
              {{ $t("comun.cancelar") }}
            </button>
          </div>
        </div>
        <p v-if="avisoVenta" class="mt-2 text-sm" style="color: var(--exito)">
          {{ avisoVenta }}
        </p>
      </div>
    </template>
  </MarcoDetalle>
</template>
