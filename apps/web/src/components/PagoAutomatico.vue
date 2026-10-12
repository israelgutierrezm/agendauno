<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import TarjetaOpenPay from "@/components/TarjetaOpenPay.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { dinero as dineroDelPais } from "@/lib/formato";
import type { FormularioOpenPay } from "@/lib/openpay";
import { useRetornoPago } from "@/lib/retornoPago";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * "Pago automático" del alumno: sus membresías que se renuevan, cuáles se cobran
 * solas y con qué tarjeta. La tarjeta se autoriza en la página de la pasarela
 * (Stripe, Mercado Pago) o en el formulario de OpenPay.js (OpenPay); AgendaUno nunca
 * la ve. Al volver, el aviso de la pasarela la registra en segundos.
 *
 * Un responsable ve también las membresías de las personas a su cargo (`para`): se
 * cobran a su tarjeta (ADR 0129).
 *
 * Seguro (ADR 0134): uno que se quitó y la pasarela aún no confirma dice que se está
 * cancelando; el monto es el que autorizó y, si el precio del plan cambió, se ofrece
 * autorizar el nuevo (hasta entonces se cobra el anterior).
 */
interface Tarjeta {
  marca: string | null;
  ultimos4: string | null;
  expira: string | null;
}
interface Membresia {
  id: string;
  // De una persona a su cargo: se cobra a la tarjeta de quien ve la cuenta.
  para?: { id: string; nombre: string } | null;
  producto: string | null;
  monto_minor: number | null;
  moneda: string | null;
  proxima_cobro_en: string | null;
  estado: string;
  automatico: boolean;
  // Suscripción creada en la pasarela que el cliente aún no autoriza.
  pendiente?: boolean;
  // Se quitó y la pasarela aún no confirma que dejó de cobrar.
  cancelando?: boolean;
  // El precio del plan cambió: el nuevo (se cobra `monto_minor` hasta autorizarlo).
  precio_nuevo_minor?: number | null;
  // Con qué tarjeta se cobra (suscripciones: una por membresía).
  tarjeta?: Tarjeta | null;
  error: string | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const disponible = ref(false);
const tarjeta = ref<Tarjeta | null>(null);
const membresias = ref<Membresia[]>([]);
const deOtros = computed(() => membresias.value.some((m) => m.para));
const accionando = ref<string | null>(null);
const error = ref<string | null>(null);
// OpenPay: la tarjeta se captura aquí con OpenPay.js antes de suscribir (al activar
// o al autorizar el nuevo precio).
const capturando = ref<{
  membresia: string;
  formulario: FormularioOpenPay;
  precio?: boolean;
} | null>(null);

const retorno = useRetornoPago("tarjeta");

function dinero(minor: number | null, moneda: string | null): string {
  if (minor === null) {
    return "—";
  }
  // Sin moneda, la del negocio (ADR 0099); con los números de su país.
  return dineroDelPais(minor, moneda ?? sesion.moneda, sesion.pais);
}
function fecha(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        day: "numeric",
        month: "long",
      }).format(new Date(`${iso}T12:00:00`))
    : "—";
}
function marca(m: string | null): string {
  return m ? m.charAt(0).toUpperCase() + m.slice(1) : "";
}

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{
      data: {
        disponible: boolean;
        tarjeta: Tarjeta | null;
        membresias: Membresia[];
      };
    }>(`${base.value}/mi/pago-automatico`);
    disponible.value = data.data.disponible;
    tarjeta.value = data.data.tarjeta;
    membresias.value = data.data.membresias;
  } catch {
    membresias.value = [];
  }
}

// Con redirección se va a la página de la pasarela; si ya había tarjeta, queda
// activo al momento.
interface RespuestaCheckout {
  data: {
    estado?: string;
    checkout?: { url?: string } | null;
    formulario?: FormularioOpenPay | null;
  };
}

async function irA(
  ruta: string,
  clave: string,
  datos: Record<string, string> = {},
  precio = false,
): Promise<void> {
  accionando.value = clave;
  error.value = null;
  try {
    const { data } = await api.post<RespuestaCheckout>(ruta, datos);
    const url = data.data.checkout?.url;
    if (typeof url === "string" && url !== "") {
      window.location.href = url;
      return;
    }
    if (data.data.estado === "formulario" && data.data.formulario) {
      capturando.value = {
        membresia: clave,
        formulario: data.data.formulario,
        precio,
      };
      return;
    }
    capturando.value = null;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = null;
  }
}

function activar(
  m: Membresia,
  tarjetaTokenizada: Record<string, string> = {},
): Promise<void> {
  return irA(
    `${base.value}/mi/pago-automatico/${m.id}`,
    m.id,
    tarjetaTokenizada,
  );
}
async function autorizarPrecio(
  m: Membresia,
  tarjetaTokenizada: Record<string, string> = {},
): Promise<void> {
  if (
    Object.keys(tarjetaTokenizada).length === 0 &&
    !(await confirmar(
      t("pagoAutomatico.confirmarPrecio", {
        nuevo: dinero(m.precio_nuevo_minor ?? null, m.moneda),
      }),
      { aceptar: t("pagoAutomatico.autorizarPrecio") },
    ))
  ) {
    return;
  }
  return irA(
    `${base.value}/mi/pago-automatico/${m.id}/nuevo-precio`,
    m.id,
    tarjetaTokenizada,
    true,
  );
}
function cambiarTarjeta(): Promise<void> {
  return irA(`${base.value}/mi/pago-automatico/tarjeta`, "tarjeta");
}

async function quitar(m: Membresia): Promise<void> {
  if (
    !(await confirmar(t("pagoAutomatico.confirmarQuitar"), { peligro: true }))
  ) {
    return;
  }
  accionando.value = m.id;
  error.value = null;
  try {
    await api.delete(`${base.value}/mi/pago-automatico/${m.id}`);
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = null;
  }
}

onMounted(async () => {
  await cargar();
  if (retorno.value === "exito") {
    window.setTimeout(() => void cargar(), 4000);
  }
});
</script>

<template>
  <div v-if="disponible && membresias.length > 0" class="tu-card p-6">
    <h2 class="font-medium text-lg">{{ $t("pagoAutomatico.titulo") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("pagoAutomatico.ayuda") }}
    </p>
    <p
      v-if="deOtros"
      class="mt-1 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("pagoAutomatico.aCargo") }}
    </p>
    <p
      v-if="retorno"
      class="mt-2 text-sm"
      role="status"
      :style="{
        color: retorno === 'exito' ? 'var(--exito)' : 'var(--aviso)',
      }"
    >
      {{
        retorno === "exito"
          ? $t("pagoAutomatico.tarjetaExito")
          : $t("pagoAutomatico.tarjetaCancelado")
      }}
    </p>
    <p v-if="error" class="mt-2 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <div
      v-if="tarjeta"
      class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm"
    >
      <span>
        {{
          $t("pagoAutomatico.tarjeta", {
            marca: marca(tarjeta.marca),
            ultimos4: tarjeta.ultimos4 ?? "····",
          })
        }}
        <span v-if="tarjeta.expira" :style="{ color: 'var(--texto-suave)' }">
          · {{ $t("pagoAutomatico.vence", { fecha: tarjeta.expira }) }}</span
        >
      </span>
      <button
        type="button"
        class="tu-enlace"
        :disabled="accionando !== null"
        @click="cambiarTarjeta"
      >
        {{ $t("pagoAutomatico.cambiarTarjeta") }}
      </button>
    </div>

    <ul class="mt-3">
      <li
        v-for="m in membresias"
        :key="m.id"
        class="flex flex-wrap items-center justify-between gap-2 border-t py-3 text-sm"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <div class="min-w-0">
          <p class="font-medium">{{ m.producto ?? "—" }}</p>
          <p
            v-if="m.para"
            class="text-xs"
            data-prueba="pago-para"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("pagoAutomatico.para", { nombre: m.para.nombre }) }}
          </p>
          <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{
              $t("pagoAutomatico.renueva", {
                fecha: fecha(m.proxima_cobro_en),
                monto: dinero(m.monto_minor, m.moneda),
              })
            }}
            ·
            <span v-if="m.automatico" class="tu-badge tu-badge-exito">{{
              $t("pagoAutomatico.automatico")
            }}</span>
            <span
              v-else-if="m.cancelando"
              class="tu-estado"
              data-prueba="cancelando"
              :style="{ '--tono': 'var(--aviso)' }"
              >{{ $t("pagoAutomatico.cancelando") }}</span
            >
            <span v-else-if="m.pendiente" class="tu-badge tu-badge-aviso">{{
              $t("pagoAutomatico.porAutorizar")
            }}</span>
            <span v-else>{{ $t("pagoAutomatico.manual") }}</span>
            <template v-if="m.automatico && m.tarjeta && !tarjeta">
              ·
              {{
                $t("pagoAutomatico.tarjeta", {
                  marca: marca(m.tarjeta.marca),
                  ultimos4: m.tarjeta.ultimos4 ?? "····",
                })
              }}</template
            >
          </p>
          <p v-if="m.error" class="mt-0.5 text-xs" style="color: var(--error)">
            {{ m.error }}
          </p>
          <p
            v-if="m.cancelando && !m.automatico"
            class="mt-0.5 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("pagoAutomatico.cancelandoAyuda") }}
          </p>
          <!-- El precio cambió: se cobra el que autorizó hasta que autorice el nuevo. -->
          <div
            v-if="m.automatico && m.precio_nuevo_minor"
            class="mt-1 text-xs"
            data-prueba="precio-nuevo"
          >
            <p :style="{ color: 'var(--aviso)' }">
              {{
                $t("pagoAutomatico.precioNuevo", {
                  nuevo: dinero(m.precio_nuevo_minor, m.moneda),
                  monto: dinero(m.monto_minor, m.moneda),
                })
              }}
            </p>
            <button
              type="button"
              class="tu-enlace mt-0.5"
              :disabled="accionando !== null"
              @click="autorizarPrecio(m)"
            >
              {{ $t("pagoAutomatico.autorizarPrecio") }}
            </button>
          </div>
          <TarjetaOpenPay
            v-if="capturando?.membresia === m.id"
            :formulario="capturando.formulario"
            @lista="
              (datos) =>
                capturando?.precio
                  ? autorizarPrecio(m, datos)
                  : activar(m, datos)
            "
            @cancelar="capturando = null"
          />
        </div>
        <button
          v-if="m.automatico"
          type="button"
          class="tu-enlace"
          :disabled="accionando !== null"
          @click="quitar(m)"
        >
          {{ $t("pagoAutomatico.quitar") }}
        </button>
        <button
          v-else
          type="button"
          class="tu-btn tu-btn-primario text-sm"
          :disabled="accionando !== null"
          @click="activar(m)"
        >
          {{ $t("pagoAutomatico.activar") }}
        </button>
      </li>
    </ul>
  </div>
</template>
