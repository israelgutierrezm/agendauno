<script setup lang="ts">
import { computed, ref, watch } from "vue";

import AvisoTono from "@/components/AvisoTono.vue";
import FormasDePago from "@/components/FormasDePago.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import { puedeEntrar } from "@/lib/acceso";
import type { SesionAgenda } from "@/lib/agenda";
import { api, mensajeDeError } from "@/lib/api";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { efectivoDe, partesListas, type ParteCobro } from "@/lib/formasDePago";
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
  // Su stock por sucursal (las que ve quien cobra).
  existencias?: { sucursal_id: string | null; stock: number }[];
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
  // El comprobante de todo cobro y cómo va su envío (R06), aparte del cobro.
  venta_id?: string | null;
  ticket: { folio: string } | null;
  ticket_envio?: EnvioTicket;
}
// Cómo va el correo del ticket (R06): sale por la cola, así que pedirlo lo deja
// pendiente hasta que se manda o falla.
type EnvioTicket = "enviado" | "pendiente" | "fallido" | null;

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
// Con varias formas de pago (ADR 0177): cuánto con cada una; suman todo.
const dividido = ref(false);
const partes = ref<ParteCobro[] | null>(null);
// Lo recibido y el cambio, solo contra lo que se paga en efectivo.
const enEfectivo = computed(() =>
  dividido.value
    ? efectivoDe(partes.value)
    : metodo.value === "efectivo"
      ? total.value
      : 0,
);
const recibidoMinor = computed(() =>
  recibido.value.trim() === "" ? null : aMinor(recibido.value, tenant.pais),
);
const cambio = computed(() =>
  recibidoMinor.value === null ? null : recibidoMinor.value - enEfectivo.value,
);
const falta = computed(() =>
  enEfectivo.value > 0 && cambio.value !== null && cambio.value < 0
    ? -cambio.value
    : 0,
);
// Lo que hay en la sucursal de la cita (R07): el stock de otra sede no se puede vender
// aquí. Sin el dato (versión anterior del API), el total.
function stockAqui(a: Articulo): number {
  const sede = props.sesion?.sucursal_id;
  if (!sede || a.existencias === undefined) return a.stock_total;
  return a.existencias.find((e) => e.sucursal_id === sede)?.stock ?? 0;
}
// Una cantidad que la sucursal ya no tiene (otra venta se llevó piezas) no se cobra.
function excede(l: Linea): boolean {
  return l.cantidad < 1 || l.cantidad > stockAqui(l.articulo);
}
const disponibles = computed(() =>
  articulos.value.filter(
    (a) =>
      a.activo &&
      stockAqui(a) > 0 &&
      !lineas.value.some((l) => l.articulo.id === a.id),
  ),
);
const puedeCobrar = computed(
  () =>
    total.value > 0 &&
    falta.value === 0 &&
    (!dividido.value || partesListas(partes.value, total.value)) &&
    !lineas.value.some(excede) &&
    !editor.guardando.value,
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
    // Lo del carrito con su precio y su stock de ahora (R07): tras un conflicto, el
    // reintento no repite lo que el servidor rechazó.
    lineas.value = lineas.value.map((l) => {
      const fresco = data.data.find((a) => a.id === l.articulo.id);
      return fresco === undefined ? l : { ...l, articulo: fresco };
    });
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
    dividido.value = false;
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
      ...(dividido.value && partes.value !== null
        ? { partes: partes.value }
        : {}),
      items: lineas.value.map((l) => ({
        articulo_id: l.articulo.id,
        cantidad: l.cantidad,
        precio_minor: l.articulo.precio_minor,
      })),
      propina_minor: propina.value,
      recibido_minor: enEfectivo.value > 0 ? recibidoMinor.value : null,
      enviar_ticket: enviarTicket.value,
      clave_intento: clave,
    }),
  );
  if (respuesta !== undefined) {
    resultado.value = respuesta.data.data;
    emit("cobrada");
  } else {
    // Pudo cambiar el stock mientras cobraba: se vuelve a leer el de la sucursal.
    void cargarArticulos();
  }
}

// Reenviar el ticket si el correo falló: solo el correo, el cobro ya quedó (R06).
const reenviando = ref(false);
const errorReenvio = ref<string | null>(null);
async function reenviarTicket(): Promise<void> {
  const r = resultado.value;
  if (r?.venta_id == null) return;
  reenviando.value = true;
  errorReenvio.value = null;
  try {
    const { data } = await api.post<{
      data: { ticket_envio: EnvioTicket };
    }>(`${props.base}/pos/ventas/${r.venta_id}/ticket`);
    r.ticket_envio = data.data.ticket_envio;
  } catch (e) {
    errorReenvio.value = mensajeDeError(e);
  } finally {
    reenviando.value = false;
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
      <!-- El envío del ticket va aparte del cobro: si falla, el cobro ya quedó. -->
      <p
        v-if="resultado.ticket_envio === 'enviado'"
        class="cc-suave"
        data-prueba="ticket-enviado"
      >
        {{ $t("cobroCita.ticketEnviado") }}
      </p>
      <p
        v-else-if="resultado.ticket_envio === 'pendiente'"
        class="cc-suave"
        data-prueba="ticket-pendiente"
      >
        {{ $t("cobroCita.ticketPendiente") }}
      </p>
      <!-- No se pidió al cobrar: se manda desde aquí (también con solo el servicio). -->
      <p
        v-else-if="
          resultado.ticket_envio == null && resultado.venta_id && cita?.email
        "
        class="cc-suave"
      >
        <button
          type="button"
          class="tu-enlace"
          :disabled="reenviando"
          data-prueba="mandar-ticket"
          @click="reenviarTicket"
        >
          {{ $t("cobroCita.mandarTicket", { correo: cita.email }) }}
        </button>
        <span v-if="errorReenvio" class="tu-error-campo block" role="alert">{{
          errorReenvio
        }}</span>
      </p>
      <AvisoTono
        v-else-if="resultado.ticket_envio === 'fallido'"
        tono="aviso"
        data-prueba="ticket-fallido"
      >
        {{ errorReenvio ?? $t("cobroCita.ticketFallido") }}
        <template #acciones>
          <button
            type="button"
            class="tu-btn tu-btn-contorno text-sm"
            :disabled="reenviando"
            data-prueba="reenviar-ticket"
            @click="reenviarTicket"
          >
            {{ $t("cobroCita.reenviarTicket") }}
          </button>
        </template>
      </AvisoTono>
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
              :max="stockAqui(l.articulo)"
              :aria-invalid="excede(l) || undefined"
              :aria-label="
                $t('cobroCita.cantidad', { nombre: l.articulo.nombre })
              "
            />
            <span
              v-if="excede(l)"
              class="tu-error-campo"
              :data-prueba="`excede-${l.articulo.id}`"
              >{{ $t("cobroCita.soloHay", { n: stockAqui(l.articulo) }) }}</span
            >
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
              {{ a.nombre }} · {{ dinero(a.precio_minor) }} ·
              {{ $t("cobroCita.enEstaSucursal", { n: stockAqui(a) }) }}
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
        <div v-if="!dividido" class="tu-segmentado flex-wrap" role="group">
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
        <FormasDePago
          v-model:dividido="dividido"
          v-model:partes="partes"
          class="mt-2"
          :total="total"
          :metodos="METODOS"
          prefijo="agendaVisual.cita.metodos"
          :inicial="metodo"
          :moneda="tenant.moneda"
          :pais="tenant.pais"
        />
      </fieldset>
      <div v-if="enEfectivo > 0">
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

      <!-- También con solo el servicio: todo cobro deja su comprobante (R06). -->
      <label v-if="cita?.email" class="flex items-center gap-2 text-sm">
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
