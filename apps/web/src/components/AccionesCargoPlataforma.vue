<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";
import { crearClientePlataforma } from "@/lib/clientePlataforma";
import { confirmar } from "@/lib/confirmar";
import { useToastStore } from "@/stores/toast";

/**
 * Lo que el superadmin hace con un cargo de la renta en la ficha (ADR 0214): registrar
 * un pago recibido fuera de la pasarela y, pagado en pesos, su factura: emitirla o
 * reintentarla, descargarla y cancelarla ante el SAT.
 */
export interface FacturaRenta {
  id: string;
  estado: "timbrada" | "error" | "cancelada";
  uuid: string | null;
  motivo_error: string | null;
  forma_pago: string | null;
  cancelacion_estado: string | null;
  cancelada_en: string | null;
}
const props = defineProps<{
  apiUrl: string;
  token: string;
  cargo: {
    id: string;
    estado: string;
    moneda: string;
    monto_minor: number;
    metodo_pago?: string | null;
    factura_detalle?: FacturaRenta | null;
  };
}>();
const emit = defineEmits<{ cambiado: [] }>();

const { t } = useI18n();
const cliente = crearClientePlataforma({});
const toast = useToastStore();

const METODOS = ["transferencia", "deposito", "efectivo", "cheque"] as const;
const pagando = ref(false);
const metodo = ref<(typeof METODOS)[number]>("transferencia");
const referencia = ref("");
const fechaPago = ref(new Date().toISOString().slice(0, 10));
const motivoCancelacion = ref<"02" | "03">("02");
const cancelando = ref(false);
const ocupado = ref(false);

const factura = computed(() => props.cargo.factura_detalle ?? null);
const facturable = computed(
  () =>
    props.cargo.estado === "pagado" &&
    props.cargo.moneda === "MXN" &&
    (factura.value === null || factura.value.estado === "error"),
);
const metodoManual = computed(() =>
  (METODOS as readonly string[]).includes(props.cargo.metodo_pago ?? "")
    ? (props.cargo.metodo_pago as (typeof METODOS)[number])
    : null,
);

function opciones() {
  return {
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${props.token}`,
    },
  };
}

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

async function hacer(
  accion: () => Promise<unknown>,
  exito: string,
): Promise<void> {
  if (ocupado.value) return;
  ocupado.value = true;
  try {
    await accion();
    toast.exito(exito);
    emit("cambiado");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

async function registrarPago(): Promise<void> {
  if (
    !(await confirmar(
      t("plataformaAdmin.rentaManual.confirmarPago", {
        monto: dinero(props.cargo.monto_minor, props.cargo.moneda),
      }),
    ))
  ) {
    return;
  }
  await hacer(
    () =>
      cliente.post(
        `${props.apiUrl}/api/v1/plataforma/cargos/${props.cargo.id}/pago`,
        {
          metodo: metodo.value,
          referencia: referencia.value.trim() || null,
          pagado_en: fechaPago.value || null,
        },
        opciones(),
      ),
    t("plataformaAdmin.rentaManual.pagoRegistrado"),
  );
  pagando.value = false;
}

async function facturar(): Promise<void> {
  await hacer(
    () =>
      cliente.post(
        `${props.apiUrl}/api/v1/plataforma/cargos/${props.cargo.id}/factura`,
        {},
        opciones(),
      ),
    t("plataformaAdmin.rentaManual.facturada"),
  );
}

async function descargar(formato: "pdf" | "xml"): Promise<void> {
  const f = factura.value;
  if (f === null) return;
  try {
    const { data } = await cliente.get<Blob>(
      `${props.apiUrl}/api/v1/plataforma/facturas/${f.id}/${formato}`,
      { ...opciones(), responseType: "blob" },
    );
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = `factura-renta-${f.uuid ?? f.id}.${formato}`;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function cancelar(): Promise<void> {
  const f = factura.value;
  if (
    f === null ||
    !(await confirmar(
      t("plataformaAdmin.rentaManual.confirmarCancelar", {
        uuid: f.uuid ?? "",
      }),
      { peligro: true },
    ))
  ) {
    return;
  }
  await hacer(
    () =>
      cliente.post(
        `${props.apiUrl}/api/v1/plataforma/facturas/${f.id}/cancelar`,
        { motivo: motivoCancelacion.value },
        opciones(),
      ),
    t("plataformaAdmin.rentaManual.cancelacionPedida"),
  );
  cancelando.value = false;
}

async function consultar(): Promise<void> {
  const f = factura.value;
  if (f === null) return;
  await hacer(
    () =>
      cliente.post(
        `${props.apiUrl}/api/v1/plataforma/facturas/${f.id}/cancelacion`,
        {},
        opciones(),
      ),
    t("plataformaAdmin.rentaManual.cancelacionRevisada"),
  );
}
</script>

<template>
  <div class="acp" data-prueba="acciones-cargo">
    <p v-if="metodoManual" class="acp-nota">
      {{
        $t("plataformaAdmin.rentaManual.pagadoCon", {
          metodo: $t(`plataformaAdmin.rentaManual.metodos.${metodoManual}`),
        })
      }}
    </p>

    <!-- Pendiente: registrar un pago recibido fuera de la pasarela. -->
    <template v-if="cargo.estado === 'pendiente'">
      <button
        v-if="!pagando"
        type="button"
        class="tu-btn tu-btn-secundario tu-btn-chico"
        data-prueba="abrir-pago-manual"
        @click="pagando = true"
      >
        {{ $t("plataformaAdmin.rentaManual.registrarPago") }}
      </button>
      <form
        v-else
        class="acp-form"
        data-prueba="pago-manual"
        @submit.prevent="registrarPago"
      >
        <label class="acp-campo">
          <span class="tu-label">{{
            $t("plataformaAdmin.rentaManual.metodo")
          }}</span>
          <select v-model="metodo" class="tu-input text-sm">
            <option v-for="m in METODOS" :key="m" :value="m">
              {{ $t(`plataformaAdmin.rentaManual.metodos.${m}`) }}
            </option>
          </select>
        </label>
        <label class="acp-campo">
          <span class="tu-label">{{
            $t("plataformaAdmin.rentaManual.referencia")
          }}</span>
          <input
            v-model="referencia"
            class="tu-input text-sm"
            maxlength="100"
            autocomplete="off"
          />
        </label>
        <label class="acp-campo">
          <span class="tu-label">{{
            $t("plataformaAdmin.rentaManual.fecha")
          }}</span>
          <input v-model="fechaPago" type="date" class="tu-input text-sm" />
        </label>
        <div class="acp-botones">
          <button
            type="submit"
            class="tu-btn tu-btn-primario tu-btn-chico"
            :disabled="ocupado"
          >
            {{ $t("plataformaAdmin.rentaManual.registrarPago") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            @click="pagando = false"
          >
            {{ $t("comun.cancelar") }}
          </button>
        </div>
      </form>
    </template>

    <!-- Pagado en pesos: su factura. -->
    <p v-if="factura?.estado === 'error'" class="acp-error" role="status">
      {{
        $t("plataformaAdmin.rentaManual.errorFactura", {
          motivo: factura.motivo_error ?? "",
        })
      }}
    </p>
    <button
      v-if="facturable"
      type="button"
      class="tu-btn tu-btn-secundario tu-btn-chico"
      :disabled="ocupado"
      data-prueba="facturar-cargo"
      @click="facturar"
    >
      {{
        factura?.estado === "error"
          ? $t("plataformaAdmin.rentaManual.reintentar")
          : $t("plataformaAdmin.rentaManual.facturar")
      }}
    </button>
    <template v-if="factura && factura.estado !== 'error'">
      <p v-if="factura.cancelacion_estado" class="acp-nota">
        {{
          $t(
            `plataformaAdmin.rentaManual.cancelacion.${factura.cancelacion_estado}`,
          )
        }}
      </p>
      <div class="acp-botones">
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          @click="descargar('pdf')"
        >
          {{ $t("plataformaAdmin.rentaManual.pdf") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          @click="descargar('xml')"
        >
          {{ $t("plataformaAdmin.rentaManual.xml") }}
        </button>
        <button
          v-if="factura.cancelacion_estado === 'pendiente'"
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          :disabled="ocupado"
          @click="consultar"
        >
          {{ $t("plataformaAdmin.rentaManual.consultar") }}
        </button>
        <button
          v-else-if="
            factura.estado === 'timbrada' &&
            factura.cancelacion_estado !== 'pendiente' &&
            !cancelando
          "
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          data-prueba="abrir-cancelar-factura"
          @click="cancelando = true"
        >
          {{ $t("plataformaAdmin.rentaManual.cancelarFactura") }}
        </button>
      </div>
      <form
        v-if="cancelando"
        class="acp-form"
        data-prueba="cancelar-factura"
        @submit.prevent="cancelar"
      >
        <label class="acp-campo">
          <span class="tu-label">{{
            $t("plataformaAdmin.rentaManual.motivoCancelacion")
          }}</span>
          <select v-model="motivoCancelacion" class="tu-input text-sm">
            <option value="02">
              {{ $t("plataformaAdmin.rentaManual.motivos.02") }}
            </option>
            <option value="03">
              {{ $t("plataformaAdmin.rentaManual.motivos.03") }}
            </option>
          </select>
        </label>
        <div class="acp-botones">
          <button
            type="submit"
            class="tu-btn tu-btn-peligro tu-btn-chico"
            :disabled="ocupado"
          >
            {{ $t("plataformaAdmin.rentaManual.cancelarFactura") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            @click="cancelando = false"
          >
            {{ $t("comun.cancelar") }}
          </button>
        </div>
      </form>
    </template>
  </div>
</template>

<style scoped>
.acp {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  margin-top: 0.5rem;
}
.acp-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  gap: 0.5rem;
  width: 100%;
}
.acp-campo {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 0;
}
.acp-botones {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.5rem;
}
.acp-nota {
  width: 100%;
  color: var(--texto-suave);
  font-size: 0.75rem;
}
.acp-error {
  width: 100%;
  color: var(--error);
  font-size: 0.75rem;
}
</style>
