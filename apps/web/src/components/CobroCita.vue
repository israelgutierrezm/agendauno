<script setup lang="ts">
import { computed, ref, watch } from "vue";

import AvisoTono from "@/components/AvisoTono.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import { puedeEntrar } from "@/lib/acceso";
import type { SesionAgenda } from "@/lib/agenda";
import { api } from "@/lib/api";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { aMinor, dinero as dineroDelPais } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Cobrar al terminar una cita en un solo paso: el servicio (si falta), los productos
 * que se lleva y la propina, con una forma de pago; en efectivo, lo que entregó y su
 * cambio. Al terminar, el comprobante. Todo se cobra junto o nada (el servidor lo
 * hace en una transacción); un reintento con la misma clave no cobra dos veces.
 */
interface Articulo {
  id: string;
  nombre: string;
  precio_minor: number;
  activo: boolean;
  stock_total: number;
}
interface Linea {
  articulo: Articulo;
  cantidad: number;
}
interface Resultado {
  servicio_minor: number;
  productos_minor: number;
  propina_minor: number;
  total_minor: number;
  recibido_minor: number | null;
  cambio_minor: number | null;
  ticket: { folio: string } | null;
}

const props = defineProps<{
  abierto: boolean;
  base: string;
  sesion: SesionAgenda | null;
}>();
const emit = defineEmits<{ cerrar: []; cobrada: [] }>();

const tenant = useSesionTenantStore();

const METODOS = ["efectivo", "tarjeta", "transferencia"] as const;
const PROPINAS = [0, 10, 15, 20] as const;

const cita = computed(() => props.sesion?.cita ?? null);
// Lo del servicio: solo si falta cobrarlo (con anticipo, solo lo que falta).
const servicio = computed(() =>
  cita.value?.por_cobrar === true
    ? (cita.value.por_cobrar_minor ?? cita.value.total_minor ?? 0)
    : 0,
);
const conMostrador = computed(() => puedeEntrar("pos", tenant));

const articulos = ref<Articulo[]>([]);
const cargandoArticulos = ref(false);
const errorArticulos = ref(false);
const lineas = ref<Linea[]>([]);
const elegido = ref("");
const metodo = ref<(typeof METODOS)[number]>("efectivo");
const propinaPct = ref<number | "otra">(0);
const propinaOtra = ref("");
const recibido = ref("");
const enviarTicket = ref(false);
const resultado = ref<Resultado | null>(null);
// Una por apertura: si la respuesta se pierde, reintentar no cobra dos veces.
let clave = "";

const valor = () => ({
  lineas: lineas.value.map((l) => [l.articulo.id, l.cantidad]),
  propinaPct: propinaPct.value,
  propinaOtra: propinaOtra.value,
  recibido: recibido.value,
});
const abiertoEditor = computed(() => props.abierto && resultado.value === null);
const editor = useEditorFormulario({
  abierto: abiertoEditor,
  valor,
  campos: ["items", "propina_minor", "recibido_minor"],
  formulario: "#form-cobro-cita",
});

function dinero(minor: number): string {
  return dineroDelPais(minor, tenant.moneda, tenant.pais, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

const productos = computed(() =>
  lineas.value.reduce((s, l) => s + l.articulo.precio_minor * l.cantidad, 0),
);
const propina = computed(() => {
  if (propinaPct.value === "otra") {
    return aMinor(propinaOtra.value, tenant.pais) ?? 0;
  }
  return Math.round(
    ((servicio.value + productos.value) * propinaPct.value) / 100,
  );
});
const total = computed(() => servicio.value + productos.value + propina.value);
const recibidoMinor = computed(() =>
  recibido.value.trim() === "" ? null : aMinor(recibido.value, tenant.pais),
);
const cambio = computed(() =>
  recibidoMinor.value === null ? null : recibidoMinor.value - total.value,
);
const falta = computed(() =>
  metodo.value === "efectivo" && cambio.value !== null && cambio.value < 0
    ? -cambio.value
    : 0,
);
const disponibles = computed(() =>
  articulos.value.filter(
    (a) =>
      a.activo &&
      a.stock_total > 0 &&
      !lineas.value.some((l) => l.articulo.id === a.id),
  ),
);
const puedeCobrar = computed(
  () => total.value > 0 && falta.value === 0 && !editor.guardando.value,
);

async function cargarArticulos(): Promise<void> {
  if (!conMostrador.value) return;
  cargandoArticulos.value = true;
  errorArticulos.value = false;
  try {
    const { data } = await api.get<{ data: Articulo[] }>(
      `${props.base}/articulos`,
    );
    articulos.value = data.data;
  } catch {
    errorArticulos.value = true;
  } finally {
    cargandoArticulos.value = false;
  }
}

watch(
  () => props.abierto,
  (abierto) => {
    if (!abierto) return;
    lineas.value = [];
    elegido.value = "";
    metodo.value = "efectivo";
    propinaPct.value = 0;
    propinaOtra.value = "";
    recibido.value = "";
    enviarTicket.value = false;
    resultado.value = null;
    clave = `cita-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
    editor.empezar();
    void cargarArticulos();
  },
  { immediate: true },
);

function agregar(): void {
  const a = articulos.value.find((x) => x.id === elegido.value);
  if (a === undefined) return;
  lineas.value = [...lineas.value, { articulo: a, cantidad: 1 }];
  elegido.value = "";
}
function quitar(i: number): void {
  lineas.value = lineas.value.filter((_, n) => n !== i);
}

async function cobrar(): Promise<void> {
  const reserva = cita.value?.reserva_id;
  if (reserva === undefined || !puedeCobrar.value) return;
  const respuesta = await editor.guardar(() =>
    api.post<{ data: Resultado }>(`${props.base}/reservas/${reserva}/cobro`, {
      metodo: metodo.value,
      items: lineas.value.map((l) => ({
        articulo_id: l.articulo.id,
        cantidad: l.cantidad,
        precio_minor: l.articulo.precio_minor,
      })),
      propina_minor: propina.value,
      recibido_minor: metodo.value === "efectivo" ? recibidoMinor.value : null,
      enviar_ticket: enviarTicket.value,
      clave_intento: clave,
    }),
  );
  if (respuesta !== undefined) {
    resultado.value = respuesta.data.data;
    emit("cobrada");
  }
}

async function cerrar(): Promise<void> {
  if (resultado.value !== null || (await editor.cerrar())) {
    emit("cerrar");
  }
}
</script>

<template>
  <ModalDialogo
    :abierto="abierto"
    :titulo="$t('cobroCita.titulo', { cliente: cita?.cliente ?? '' })"
    tam="md"
    :ocupado="editor.guardando.value"
    @cerrar="cerrar"
  >
    <!-- El comprobante -->
    <div v-if="resultado" class="space-y-3" data-prueba="comprobante-cita">
      <AvisoTono tono="exito">{{ $t("cobroCita.hecho") }}</AvisoTono>
      <dl class="cc-lineas">
        <div v-if="resultado.servicio_minor > 0">
          <dt>{{ sesion?.oferta ?? $t("cobroCita.servicio") }}</dt>
          <dd>{{ dinero(resultado.servicio_minor) }}</dd>
        </div>
        <div v-if="resultado.productos_minor > 0">
          <dt>{{ $t("cobroCita.productos") }}</dt>
          <dd>{{ dinero(resultado.productos_minor) }}</dd>
        </div>
        <div v-if="resultado.propina_minor > 0">
          <dt>{{ $t("cobroCita.propina") }}</dt>
          <dd>{{ dinero(resultado.propina_minor) }}</dd>
        </div>
        <div class="cc-total">
          <dt>{{ $t("cobroCita.total") }}</dt>
          <dd>{{ dinero(resultado.total_minor) }}</dd>
        </div>
        <template v-if="resultado.cambio_minor !== null">
          <div>
            <dt>{{ $t("cobroCita.recibido") }}</dt>
            <dd>{{ dinero(resultado.recibido_minor ?? 0) }}</dd>
          </div>
          <div>
            <dt>{{ $t("cobroCita.cambio") }}</dt>
            <dd data-prueba="cambio-cita">
              {{ dinero(resultado.cambio_minor) }}
            </dd>
          </div>
        </template>
      </dl>
      <p v-if="resultado.ticket" class="cc-suave">
        {{ $t("cobroCita.folio", { folio: resultado.ticket.folio }) }}
      </p>
      <div class="flex justify-end">
        <button type="button" class="tu-btn tu-btn-primario" @click="cerrar">
          {{ $t("cobroCita.listo") }}
        </button>
      </div>
    </div>

    <form
      v-else
      id="form-cobro-cita"
      class="space-y-4"
      novalidate
      @submit.prevent="cobrar"
    >
      <AvisoTono v-if="editor.error.value" tono="peligro" role="alert">{{
        editor.error.value
      }}</AvisoTono>

      <dl class="cc-lineas">
        <div v-if="servicio > 0">
          <dt>{{ sesion?.oferta ?? $t("cobroCita.servicio") }}</dt>
          <dd>{{ dinero(servicio) }}</dd>
        </div>
        <p v-else class="cc-suave">{{ $t("cobroCita.servicioPagado") }}</p>
        <div v-for="(l, i) in lineas" :key="l.articulo.id">
          <dt class="flex items-center gap-2">
            {{ l.articulo.nombre }}
            <input
              v-model.number="l.cantidad"
              class="tu-input w-16"
              type="number"
              min="1"
              :max="l.articulo.stock_total"
              :aria-label="
                $t('cobroCita.cantidad', { nombre: l.articulo.nombre })
              "
            />
            <button type="button" class="tu-enlace text-sm" @click="quitar(i)">
              {{ $t("cobroCita.quitar") }}
            </button>
          </dt>
          <dd>{{ dinero(l.articulo.precio_minor * l.cantidad) }}</dd>
        </div>
      </dl>
      <p v-if="editor.errores.value.items" class="tu-error-campo" role="alert">
        {{ editor.errores.value.items }}
      </p>

      <!-- Productos que se lleva (si hay mostrador) -->
      <div v-if="conMostrador">
        <label class="tu-label" for="cc-producto">{{
          $t("cobroCita.agregarProducto")
        }}</label>
        <p v-if="cargandoArticulos" class="cc-suave">
          {{ $t("comun.cargando") }}
        </p>
        <p
          v-else-if="errorArticulos"
          class="text-sm"
          :style="{ color: 'var(--error)' }"
          data-prueba="error-articulos-cita"
        >
          {{ $t("cobroCita.errorProductos") }}
          <button type="button" class="tu-enlace" @click="cargarArticulos">
            {{ $t("comun.reintentar") }}
          </button>
        </p>
        <div v-else class="flex gap-2">
          <select
            id="cc-producto"
            v-model="elegido"
            class="tu-input"
            data-prueba="producto-cita"
          >
            <option value="">
              {{
                disponibles.length > 0
                  ? $t("cobroCita.elegirProducto")
                  : $t("cobroCita.sinProductos")
              }}
            </option>
            <option v-for="a in disponibles" :key="a.id" :value="a.id">
              {{ a.nombre }} · {{ dinero(a.precio_minor) }}
            </option>
          </select>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="elegido === ''"
            data-prueba="agregar-producto-cita"
            @click="agregar"
          >
            {{ $t("cobroCita.agregar") }}
          </button>
        </div>
      </div>

      <!-- Propina -->
      <fieldset>
        <legend class="tu-label">{{ $t("cobroCita.propina") }}</legend>
        <div class="tu-segmentado flex-wrap" role="group">
          <button
            v-for="p in PROPINAS"
            :key="p"
            type="button"
            :aria-pressed="propinaPct === p"
            @click="propinaPct = p"
          >
            {{ p === 0 ? $t("cobroCita.sinPropina") : `${p} %` }}
          </button>
          <button
            type="button"
            :aria-pressed="propinaPct === 'otra'"
            @click="propinaPct = 'otra'"
          >
            {{ $t("cobroCita.otra") }}
          </button>
        </div>
        <input
          v-if="propinaPct === 'otra'"
          v-model="propinaOtra"
          class="tu-input mt-2 w-40"
          inputmode="decimal"
          :aria-label="$t('cobroCita.propina')"
          data-prueba="propina-otra"
          v-bind="editor.marcar('propina_minor', 'cc-err-propina')"
        />
        <p
          v-if="editor.errores.value.propina_minor"
          id="cc-err-propina"
          class="tu-error-campo"
        >
          {{ editor.errores.value.propina_minor }}
        </p>
      </fieldset>

      <!-- Forma de pago y, en efectivo, lo que entregó -->
      <fieldset>
        <legend class="tu-label">{{ $t("cobroCita.metodo") }}</legend>
        <div class="tu-segmentado flex-wrap" role="group">
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
      </fieldset>
      <div v-if="metodo === 'efectivo'">
        <label class="tu-label" for="cc-recibido">{{
          $t("cobroCita.recibe")
        }}</label>
        <input
          id="cc-recibido"
          v-model="recibido"
          class="tu-input w-40"
          inputmode="decimal"
          data-prueba="recibido-cita"
          v-bind="editor.marcar('recibido_minor', 'cc-err-recibido')"
        />
        <p
          v-if="editor.errores.value.recibido_minor"
          id="cc-err-recibido"
          class="tu-error-campo"
        >
          {{ editor.errores.value.recibido_minor }}
        </p>
        <p
          v-else-if="falta > 0"
          class="tu-error-campo"
          data-prueba="falta-cita"
        >
          {{ $t("cobroCita.falta", { monto: dinero(falta) }) }}
        </p>
        <p v-else-if="cambio !== null" class="cc-suave">
          {{ $t("cobroCita.suCambio", { monto: dinero(cambio) }) }}
        </p>
      </div>

      <label
        v-if="cita?.email && (lineas.length > 0 || propina > 0)"
        class="flex items-center gap-2 text-sm"
      >
        <input v-model="enviarTicket" type="checkbox" />
        {{ $t("cobroCita.enviarTicket", { correo: cita.email }) }}
      </label>

      <div class="flex items-center justify-between gap-3">
        <span
          class="text-lg font-semibold tabular-nums"
          data-prueba="total-cita"
        >
          {{ dinero(total) }}
        </span>
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="!puedeCobrar"
          data-prueba="cobrar-cita"
        >
          {{
            editor.guardando.value
              ? $t("cobroCita.cobrando")
              : $t("cobroCita.cobrar", { monto: dinero(total) })
          }}
        </button>
      </div>
    </form>
  </ModalDialogo>
</template>

<style scoped>
.cc-lineas {
  display: grid;
  gap: 0.5rem;
}
.cc-lineas > div {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}
.cc-lineas dd {
  font-variant-numeric: tabular-nums;
}
.cc-total {
  border-top: 1px solid var(--borde);
  padding-top: 0.5rem;
  font-weight: 600;
}
.cc-suave {
  color: var(--texto-suave);
  font-size: 0.875rem;
}
</style>
