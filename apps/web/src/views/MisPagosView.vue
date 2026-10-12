<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import ComprasMostrador from "@/components/ComprasMostrador.vue";
import CortePlanes from "@/components/CortePlanes.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import HistorialCompras from "@/components/HistorialCompras.vue";
import IconoNav from "@/components/IconoNav.vue";
import PagoAutomatico from "@/components/PagoAutomatico.vue";
import { api, mensajeDeError } from "@/lib/api";
import { participanteEnUso } from "@/lib/participanteEnUso";
import { textoCobertura } from "@/lib/cobertura";
import {
  cuandoCorto,
  dinero,
  useMiCuenta,
  type Orden,
  type Voucher,
} from "@/lib/miCuenta";
import { useRetornoPago } from "@/lib/retornoPago";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Pagos del portal, según cómo trabaja el negocio (ADR 0091):
 * - Clases: su plan vigente (compacto; los anteriores plegados), lo que tiene por
 *   pagar, comprar o renovar, el pago automático y el historial.
 * - Citas: lo que tiene por pagar (servicio, con quién, cuándo) y el historial de
 *   cobros; sus bonos y comprarlos, solo si los tiene o el negocio los vende.
 * Lo que debe llega completo aparte (no de la primera página del historial).
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const cuenta = useMiCuenta();
const retornoPago = useRetornoPago();
const corte = ref<InstanceType<typeof CortePlanes> | null>(null);
const historial = ref<InstanceType<typeof HistorialCompras> | null>(null);
if (retornoPago.value === "exito") {
  // El webhook de la pasarela confirma el pago en segundos.
  window.setTimeout(() => {
    void cuenta.cargar(true);
    void corte.value?.cargar();
    void historial.value?.cargar();
  }, 4000);
}

const citas = computed(() => sesion.esCitas === true);
// En citas, «Mis planes» solo si tiene alguno (bono o membresía).
const mostrarPlanes = computed(
  () => !citas.value || cuenta.derechos.value.length > 0,
);
// Segunda línea de lo que debe: la cita (con quién, cuándo y dónde).
function detalleCita(o: Orden): string | null {
  if (!o.sesion) {
    return null;
  }
  return [
    o.sesion.profesional
      ? t("portal.pagos.con", { nombre: o.sesion.profesional })
      : null,
    cuandoCorto(o.sesion.inicia_en, o.sesion.zona_horaria),
    o.sesion.sucursal,
  ]
    .filter(Boolean)
    .join(" · ");
}

const pagando = ref<string | null>(null);
const comprando = ref<string | null>(null);
const voucher = ref<Voucher | null>(null);
const mensaje = ref<string | null>(null);
const error = ref<string | null>(null);
const domiciliar = ref<Record<string, boolean>>({});
// Por una persona a cargo, el pago automático va a la tarjeta del responsable (ADR 0129).
const porPersonaACargo = participanteEnUso() !== null;

// Las clases extra se suman a un paquete: solo se ofrecen a quien tiene uno.
const tienePaquete = computed(() =>
  cuenta.derechos.value.some((d) => !d.ilimitado),
);
// La clase de prueba solo la ve quien todavía puede comprarla (nunca vino; B03). El
// destacado del negocio va primero.
const comprables = computed(() =>
  cuenta.productos.value
    .filter(
      (p) =>
        (p.tipo !== "add_on" || tienePaquete.value) && p.disponible !== false,
    )
    .sort(
      (a, b) => Number(b.destacado ?? false) - Number(a.destacado ?? false),
    ),
);
// «8 de octubre» de una fecha AAAA-MM-DD, sin que la zona horaria la mueva.
function fechaDia(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
    timeZone: "UTC",
  }).format(new Date(`${iso}T12:00:00Z`));
}
function vigencia(p: {
  tipo: string;
  vigencia_tipo?: string | null;
  vigencia_cantidad?: number | null;
}): string | null {
  if (p.tipo === "add_on") {
    return t("planes.resumen.conElPaquete");
  }
  const n = p.vigencia_cantidad ?? 0;
  switch (p.vigencia_tipo) {
    case "dias":
      return t("planes.resumen.dias", { n }, n);
    case "meses":
      return t("planes.resumen.meses", { n }, n);
    case "fin_de_mes":
      return t("planes.resumen.finDeMes", { n }, n);
    default:
      return null;
  }
}
// Con pasarela de redirección se va al checkout; con pago en tienda se muestra la
// referencia; si no, el pago queda en proceso.
async function pagar(
  ordenId: string,
  metodo: "tarjeta" | "oxxo" = "tarjeta",
): Promise<void> {
  pagando.value = ordenId;
  error.value = null;
  mensaje.value = null;
  voucher.value = null;
  try {
    const { data } = await api.post<{
      data: { checkout?: ({ tipo?: string; url?: string } & Voucher) | null };
    }>(`${cuenta.base.value}/mi/ordenes/${ordenId}/cobrar`, {
      metodo,
      domiciliar: metodo === "tarjeta" && domiciliar.value[ordenId] === true,
    });
    const checkout = data.data.checkout ?? {};
    if (checkout.tipo === "voucher") {
      voucher.value = checkout;
      return;
    }
    if (checkout.tipo === "redirect" && checkout.url) {
      window.location.href = checkout.url;
      return;
    }
    mensaje.value = "pago";
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    pagando.value = null;
  }
}

// Las condiciones que aceptó, por plan (permanencia; B04).
const aceptadas = ref<Record<string, boolean>>({});
async function comprar(id: string): Promise<void> {
  const p = cuenta.productos.value.find((x) => x.id === id);
  if (!p || (p.condiciones && !aceptadas.value[id])) {
    return;
  }
  comprando.value = id;
  mensaje.value = null;
  if (await cuenta.comprar(p, !!p.condiciones)) {
    mensaje.value = "comprado";
  }
  comprando.value = null;
}

onMounted(() => void cuenta.asegurar());
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('portal.pagos.titulo')"
      :subtitulo="
        $t(
          citas
            ? 'portal.pagos.descripcionCitas'
            : 'portal.pagos.descripcionClases',
        )
      "
    />

    <nav
      v-if="
        !cuenta.cargando.value && (!cuenta.error.value || cuenta.cargado.value)
      "
      class="mp-accesos"
      :aria-label="$t('portal.pagos.secciones')"
    >
      <a
        href="#mp-pendientes"
        class="tu-card mp-acceso"
        :class="{ 'mp-atencion': cuenta.porPagar.value.length > 0 }"
      >
        <span class="mp-icono"><IconoNav nombre="ventas" :tam="26" /></span>
        <span
          ><small>{{ $t("portal.pagos.porPagar") }}</small
          ><strong>{{
            cuenta.porPagar.value.length
              ? $t(
                  "portal.inicio.tarjetas.porPagar",
                  cuenta.porPagar.value.length,
                )
              : $t("portal.inicio.tarjetas.alCorriente")
          }}</strong></span
        >
      </a>
      <a v-if="mostrarPlanes" href="#mp-planes" class="tu-card mp-acceso"
        ><span class="mp-icono"><IconoNav nombre="etiqueta" :tam="26" /></span
        ><span
          ><strong>{{ $t("portal.pagos.planes") }}</strong
          ><small>{{ $t("portal.pagos.saldoVigencia") }}</small></span
        ></a
      >
      <a href="#mp-historial" class="tu-card mp-acceso"
        ><span class="mp-icono"><IconoNav nombre="lista" :tam="26" /></span
        ><span
          ><strong>{{ $t("portal.pagos.historial") }}</strong
          ><small>{{ $t("portal.pagos.verMovimientos") }}</small></span
        ></a
      >
      <a v-if="comprables.length" href="#mp-comprar" class="tu-card mp-acceso"
        ><span class="mp-icono"><IconoNav nombre="dinero" :tam="26" /></span
        ><span
          ><strong>{{ $t("portal.pagos.renovar") }}</strong
          ><small>{{ $t("portal.pagos.verOpciones") }}</small></span
        ></a
      >
    </nav>

    <p
      v-if="error || cuenta.error.value"
      class="mt-3 text-sm"
      style="color: var(--error)"
    >
      {{ error ?? cuenta.error.value }}
      <button
        v-if="cuenta.error.value"
        type="button"
        class="tu-enlace ml-2"
        @click="cuenta.cargar(true)"
      >
        {{ $t("comun.reintentar") }}
      </button>
    </p>
    <p
      v-if="retornoPago"
      class="mt-3 text-sm"
      role="status"
      :style="{
        color: retornoPago === 'exito' ? 'var(--exito)' : 'var(--aviso)',
      }"
    >
      {{ $t(`pagoEnLinea.${retornoPago}`) }}
      <!-- Pagó: el siguiente paso es reservar con su plan (C05). -->
      <RouterLink
        v-if="retornoPago === 'exito' && !sesion.esCitas"
        :to="{
          name: 'mis-reservas',
          query: { vista: 'reservar', incluidas: '1' },
        }"
        class="tu-enlace ml-1"
        data-prueba="reservar-tras-pago"
        >{{ $t("portal.miReserva.reservarConPlan") }}</RouterLink
      >
    </p>
    <p
      v-if="mensaje === 'pago'"
      class="mt-3 text-sm"
      role="status"
      :style="{ color: 'var(--exito)' }"
    >
      {{ $t("miCuentaExtra.pagoEnProceso") }}
    </p>

    <!-- Referencia para pagar en tienda -->
    <div v-if="voucher" class="mt-4 tu-card p-5 text-sm" role="status">
      <p class="font-medium">{{ $t("pagoTienda.titulo") }}</p>
      <p class="mt-1">
        {{ $t("pagoTienda.referencia", { referencia: voucher.referencia }) }}
      </p>
      <img
        v-if="voucher.codigo_barras"
        :src="voucher.codigo_barras"
        alt=""
        class="mt-2 h-12 max-w-full"
      />
      <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("pagoTienda.ayuda") }}
      </p>
      <a
        v-if="voucher.recibo"
        :href="voucher.recibo"
        target="_blank"
        rel="noopener"
        class="tu-enlace mt-2 inline-block"
        >{{ $t("pagoTienda.recibo") }}</a
      >
    </div>

    <p
      v-if="cuenta.cargando.value"
      class="mt-6"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <div
      v-else-if="!cuenta.error.value || cuenta.cargado.value"
      class="mp-pagos"
    >
      <!-- Clases: su plan vigente primero. En citas, sus bonos van después. -->
      <div
        v-if="mostrarPlanes"
        id="mp-planes"
        :class="citas ? 'order-3' : 'order-1'"
      >
        <CortePlanes ref="corte" :url="`${cuenta.base.value}/mi/planes`" />
      </div>

      <div id="mp-pendientes" class="order-2">
        <!-- Por pagar -->
        <div class="tu-card p-5" data-prueba="por-pagar">
          <h2 class="mp-seccion">
            <IconoNav nombre="ventas" :tam="22" />{{
              $t("portal.pagos.porPagar")
            }}<span v-if="cuenta.porPagar.value.length" class="tu-badge">{{
              cuenta.porPagar.value.length
            }}</span>
          </h2>
          <!-- La pasarela del negocio está en pruebas (M07). -->
          <p
            v-if="cuenta.pagoEnLinea.value && cuenta.pagoEnLineaPrueba.value"
            class="mt-2 text-sm"
            role="status"
            :style="{ color: 'var(--aviso)' }"
            data-prueba="modo-prueba"
          >
            {{ $t("miCuentaExtra.modoPrueba") }}
          </p>
          <ul
            v-if="cuenta.porPagar.value.length > 0"
            class="mt-3 divide-y divide-[var(--borde)] text-sm"
          >
            <li v-for="o in cuenta.porPagar.value" :key="o.id" class="py-3">
              <div class="flex items-baseline justify-between gap-3">
                <span class="min-w-0">
                  <span class="block font-medium">{{ o.concepto || "—" }}</span>
                  <span
                    v-if="detalleCita(o)"
                    class="block text-sm first-letter:uppercase"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ detalleCita(o) }}</span
                  >
                </span>
                <span class="shrink-0 font-semibold tabular-nums">{{
                  dinero(o.total_minor, o.moneda)
                }}</span>
              </div>
              <!-- Ya inició un pago que espera confirmación: para no pagar dos veces. -->
              <p
                v-if="o.pago_en_proceso"
                class="mt-2 text-xs"
                role="status"
                :style="{ color: 'var(--texto-suave)' }"
                data-prueba="pago-en-proceso"
              >
                {{
                  $t(
                    o.pago_en_proceso.metodo === "oxxo"
                      ? "miCuentaExtra.enProcesoOxxo"
                      : "miCuentaExtra.enProceso",
                  )
                }}
              </p>
              <div
                v-if="cuenta.pagoEnLinea.value"
                class="mt-2 flex flex-wrap items-center gap-3"
              >
                <button
                  class="tu-btn tu-btn-primario text-sm"
                  :disabled="pagando !== null"
                  @click="pagar(o.id)"
                >
                  {{
                    pagando === o.id
                      ? $t("miCuentaExtra.pagando")
                      : $t("miCuentaExtra.pagar")
                  }}
                </button>
                <button
                  type="button"
                  class="tu-enlace text-xs"
                  :disabled="pagando !== null"
                  @click="pagar(o.id, 'oxxo')"
                >
                  {{ $t("pagoTienda.pagarOxxo") }}
                </button>
                <label
                  v-if="cuenta.pagoAutomatico.value && o.recurrente"
                  class="flex items-center gap-1.5 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  <input v-model="domiciliar[o.id]" type="checkbox" />
                  {{
                    porPersonaACargo
                      ? $t("pagoAutomatico.alPagarACargo")
                      : $t("pagoAutomatico.alPagar")
                  }}
                </label>
              </div>
            </li>
          </ul>
          <p v-else class="mp-al-corriente">
            <IconoNav nombre="hecho" :tam="22" />
            {{ $t("portal.inicio.tarjetas.alCorriente") }}
          </p>
          <p class="mt-3 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("miCuenta.compras.nota") }}
          </p>
        </div>
      </div>

      <!-- Comprar o renovar -->
      <div
        v-if="comprables.length > 0"
        id="mp-comprar"
        class="order-4 tu-card p-5"
      >
        <h2 class="mp-seccion">
          <IconoNav nombre="etiqueta" :tam="22" />{{
            $t("miCuenta.comprar.titulo")
          }}
        </h2>
        <p
          v-if="mensaje === 'comprado'"
          class="mt-2 text-sm"
          :style="{ color: 'var(--exito)' }"
        >
          {{ $t("miCuenta.comprar.creada") }}
        </p>
        <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <li
            v-for="p in comprables"
            :key="p.id"
            class="mp-producto flex flex-col rounded-xl border p-4"
            :style="{
              borderColor: p.destacado ? 'var(--primario)' : 'var(--borde)',
            }"
            data-prueba="comprable"
          >
            <span class="text-xs" :style="{ color: 'var(--texto-suave)' }">{{
              [
                $t(`miCuenta.comprar.tipos.${p.tipo}`),
                p.destacado ? $t("miCuenta.comprar.destacado") : null,
                p.primera_vez ? $t("miCuenta.comprar.primeraVez") : null,
              ]
                .filter(Boolean)
                .join(" · ")
            }}</span>
            <p class="mt-1 font-semibold">{{ p.nombre }}</p>
            <p v-if="p.descripcion" class="mt-1 text-sm">
              {{ p.descripcion }}
            </p>
            <p
              v-if="p.todas_sucursales !== undefined"
              class="mt-1 text-xs"
              style="color: var(--texto-suave)"
            >
              {{ textoCobertura(p, $t) }}
            </p>
            <p class="mt-1 text-xl font-semibold">
              {{ dinero(p.precio_minor, p.moneda) }}
            </p>
            <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
              <template v-if="p.ilimitado">{{
                $t("miCuenta.ilimitado")
              }}</template>
              <template v-else-if="p.creditos_incluidos">{{
                $t(
                  "miCuenta.comprar.creditos",
                  { n: p.creditos_incluidos / 1000 },
                  p.creditos_incluidos === 1000 ? 1 : 2,
                )
              }}</template>
            </p>
            <p
              v-if="vigencia(p)"
              class="text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ vigencia(p) }}
            </p>
            <!-- La inscripción que se suma, antes de comprar (B04). -->
            <p
              v-if="(p.inscripcion_minor ?? 0) > 0"
              class="text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              data-prueba="inscripcion"
            >
              {{
                $t("miCuenta.comprar.masInscripcion", {
                  monto: dinero(p.inscripcion_minor ?? 0, p.moneda),
                })
              }}
            </p>
            <!-- La fecha exacta: la vigencia corre desde el pago (M03). -->
            <p
              v-if="p.vence_si_compra_hoy"
              class="text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              data-prueba="vence"
            >
              {{
                $t("miCuenta.comprar.vence", {
                  fecha: fechaDia(p.vence_si_compra_hoy),
                })
              }}
            </p>
            <!-- Permanencia (B04): las condiciones, aceptadas antes de comprar. -->
            <div
              v-if="p.condiciones"
              class="mt-2 space-y-1 text-xs"
              data-prueba="condiciones"
            >
              <p>{{ p.condiciones }}</p>
              <label class="flex items-start gap-2">
                <input
                  v-model="aceptadas[p.id]"
                  type="checkbox"
                  class="mt-0.5"
                />
                {{ $t("miCuenta.comprar.aceptoCondiciones") }}
              </label>
            </div>
            <button
              class="tu-btn tu-btn-primario mt-auto w-full"
              :class="{ 'mt-3': true }"
              :disabled="
                comprando !== null || (!!p.condiciones && !aceptadas[p.id])
              "
              @click="comprar(p.id)"
            >
              {{
                comprando === p.id
                  ? $t("miCuenta.comprar.comprando")
                  : $t("miCuenta.comprar.comprar")
              }}
            </button>
          </li>
        </ul>
      </div>

      <!-- Pago automático -->
      <PagoAutomatico v-if="cuenta.personaId.value !== null" class="order-5" />

      <!-- Historial: en citas, justo después de lo que debe -->
      <div id="mp-historial" :class="citas ? 'order-2' : 'order-6'">
        <HistorialCompras ref="historial" />
      </div>

      <!-- Lo que compró en el mostrador (ADR 0145); sin compras, no aparece. -->
      <ComprasMostrador class="order-7" />
    </div>
  </section>
</template>

<style scoped>
/* Las tarjetas de Pagos en columna; su orden cambia con la modalidad. */
.mp-pagos {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  margin-top: 1.25rem;
}
.mp-accesos {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 0.8rem;
  margin-top: 1.25rem;
}
.mp-acceso {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 1rem;
  text-decoration: none;
}
.mp-acceso:hover {
  border-color: var(--primario);
}
.mp-acceso strong {
  display: block;
  font-size: 0.95rem;
}
.mp-acceso small {
  display: block;
  color: var(--texto-suave);
  font-size: 0.75rem;
}
.mp-icono {
  display: inline-flex;
  flex-shrink: 0;
  color: var(--primario);
}
.mp-atencion .mp-icono {
  color: var(--aviso);
}
.mp-seccion {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.6rem;
  font-weight: 600;
}
.mp-seccion > svg {
  color: var(--primario);
}
.mp-al-corriente {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 1rem;
  border-radius: 10px;
  background: var(--exito-suave);
  color: var(--exito-texto);
  margin-top: 1rem;
  font-size: 0.9rem;
}
.mp-producto {
  background: var(--superficie-2);
  gap: 0.2rem;
}
/* En el teléfono, los accesos en dos columnas y sin descripción: lo primero que se
   ve sigue siendo lo que debe y su plan. */
@media (max-width: 639px) {
  .mp-accesos {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.5rem;
  }
  .mp-acceso {
    gap: 0.6rem;
    padding: 0.75rem;
  }
  .mp-acceso strong {
    font-size: 0.875rem;
  }
  .mp-acceso strong + small {
    display: none;
  }
}
#mp-planes,
#mp-pendientes,
#mp-historial,
#mp-comprar {
  scroll-margin-top: 6rem;
}
</style>
