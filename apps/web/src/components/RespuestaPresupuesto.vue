<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import type { PresupuestoCliente } from "@/components/DetallePresupuesto.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";

/**
 * Lo que el cliente hace con su presupuesto (en línea o en su cuenta): cómo está,
 * aceptarlo (se vuelve su cobro) o rechazarlo con un motivo, pagarlo en línea y agendar
 * sus servicios. `url` es la dirección del presupuesto en el API; `token`, el del
 * enlace del correo (sin él, la sesión de su cuenta).
 */
const props = defineProps<{
  presupuesto: PresupuestoCliente;
  url: string;
  slug: string;
  token?: string;
}>();
const emit = defineEmits<{ actualizado: [p: PresupuestoCliente] }>();
const { t } = useI18n();

const ocupado = ref<"aceptar" | "rechazar" | "pagar" | null>(null);
const error = ref<string | null>(null);
const rechazando = ref(false);
const motivo = ref("");
const conToken = computed(() =>
  props.token ? { t: props.token } : ({} as Record<string, string>),
);

// Cómo está, con punto y texto.
const estado = computed<{ texto: string; tono: string }>(() => {
  const p = props.presupuesto;
  if (p.estado === "rechazado") {
    return {
      texto: t("presupuestos.enLinea.rechazado"),
      tono: "var(--texto-suave)",
    };
  }
  if (p.orden?.estado === "pagada") {
    return { texto: t("presupuestos.enLinea.pagado"), tono: "var(--exito)" };
  }
  if (p.estado === "convertido" || p.estado === "aceptado") {
    return { texto: t("presupuestos.enLinea.aceptado"), tono: "var(--exito)" };
  }
  if (p.vencido) {
    return { texto: t("presupuestos.enLinea.vencido"), tono: "var(--peligro)" };
  }
  return {
    texto: t("presupuestos.enLinea.porResponder"),
    tono: "var(--aviso)",
  };
});

async function aceptar(): Promise<void> {
  if (
    !(await confirmar(t("presupuestos.enLinea.confirmarAceptar"), {
      aceptar: t("presupuestos.enLinea.aceptar"),
      tono: "exito",
    }))
  ) {
    return;
  }
  ocupado.value = "aceptar";
  error.value = null;
  try {
    const { data } = await api.post<{ data: PresupuestoCliente }>(
      `${props.url}/aceptar`,
      { ...conToken.value, revision: props.presupuesto.revision },
    );
    emit("actualizado", data.data);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = null;
  }
}

async function rechazar(): Promise<void> {
  ocupado.value = "rechazar";
  error.value = null;
  try {
    const { data } = await api.post<{ data: PresupuestoCliente }>(
      `${props.url}/rechazar`,
      { ...conToken.value, motivo: motivo.value.trim() || null },
    );
    rechazando.value = false;
    emit("actualizado", data.data);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = null;
  }
}

async function pagar(): Promise<void> {
  ocupado.value = "pagar";
  error.value = null;
  try {
    const { data } = await api.post<{
      data: { checkout: { url?: string } | null };
    }>(`${props.url}/pagar`, {
      ...conToken.value,
      idempotency_key: `presupuesto-${props.presupuesto.id}-${props.presupuesto.orden?.id ?? ""}`,
    });
    const destino = data.data.checkout?.url;
    if (destino) {
      window.location.assign(destino);
      return;
    }
    error.value = t("presupuestos.enLinea.sinCheckout");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = null;
  }
}

function agendar(servicio: string): string {
  const sede = props.presupuesto.sucursal?.id;
  return `/agendar/${encodeURIComponent(props.slug)}?servicio=${encodeURIComponent(servicio)}${sede ? `&sucursal=${encodeURIComponent(sede)}` : ""}`;
}
</script>

<template>
  <div class="rp" data-prueba="respuesta-presupuesto">
    <p
      class="tu-estado text-sm"
      :style="{ '--tono': estado.tono }"
      data-prueba="presupuesto-estado"
    >
      {{ estado.texto }}
    </p>

    <AvisoTono v-if="error" tono="peligro" class="mt-3" role="alert">
      {{ error }}
    </AvisoTono>

    <!-- Aceptar o rechazar mientras está vigente -->
    <div
      v-if="presupuesto.puede_responder && !rechazando"
      class="mt-4 flex flex-wrap gap-2"
    >
      <button
        type="button"
        class="tu-btn tu-btn-primario"
        :disabled="ocupado !== null"
        data-prueba="presupuesto-aceptar"
        @click="aceptar"
      >
        {{
          ocupado === "aceptar"
            ? $t("presupuestos.enLinea.aceptando")
            : $t("presupuestos.enLinea.aceptar")
        }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-secundario"
        :disabled="ocupado !== null"
        data-prueba="presupuesto-rechazar"
        @click="rechazando = true"
      >
        {{ $t("presupuestos.enLinea.rechazar") }}
      </button>
    </div>
    <div v-if="rechazando" class="mt-4 max-w-md">
      <label class="tu-label" :for="`motivo-${presupuesto.id}`">{{
        $t("presupuestos.enLinea.motivo")
      }}</label>
      <textarea
        :id="`motivo-${presupuesto.id}`"
        v-model="motivo"
        class="tu-input"
        rows="3"
        maxlength="500"
      />
      <!-- En línea, bajo el motivo: «Cancelar» solo descarta lo escrito. -->
      <div class="mt-2 flex flex-wrap gap-2">
        <button
          type="button"
          class="tu-btn tu-btn-peligro tu-btn-chico"
          :disabled="ocupado !== null"
          :aria-busy="ocupado === 'rechazar' || undefined"
          data-prueba="presupuesto-confirmar-rechazo"
          @click="rechazar"
        >
          {{ $t("presupuestos.enLinea.confirmarRechazo") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          :disabled="ocupado !== null"
          @click="rechazando = false"
        >
          {{ $t("comun.cancelar") }}
        </button>
      </div>
    </div>
    <p
      v-if="presupuesto.vencido && presupuesto.estado === 'enviado'"
      class="tu-hint mt-3"
    >
      {{ $t("presupuestos.enLinea.vencidoAyuda") }}
    </p>

    <!-- Aceptado: pagarlo en línea (o en el negocio) y agendar sus servicios -->
    <template v-if="presupuesto.orden?.estado === 'pendiente'">
      <button
        v-if="presupuesto.puede_pagar"
        type="button"
        class="tu-btn tu-btn-primario mt-4"
        :disabled="ocupado !== null"
        data-prueba="presupuesto-pagar"
        @click="pagar"
      >
        {{
          ocupado === "pagar"
            ? $t("presupuestos.enLinea.abriendoPago")
            : $t("presupuestos.enLinea.pagar")
        }}
      </button>
      <p v-else class="tu-hint mt-3">
        {{ $t("presupuestos.enLinea.pagarEnNegocio") }}
      </p>
    </template>
    <div
      v-if="
        presupuesto.servicios.length > 0 &&
        (presupuesto.estado === 'convertido' ||
          presupuesto.estado === 'aceptado')
      "
      class="mt-4"
    >
      <p class="tu-label">{{ $t("presupuestos.enLinea.agendarTitulo") }}</p>
      <div class="flex flex-wrap gap-2">
        <a
          v-for="s in presupuesto.servicios"
          :key="s.id"
          :href="agendar(s.id)"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          data-prueba="presupuesto-agendar"
        >
          {{ $t("presupuestos.enLinea.agendar", { servicio: s.nombre }) }}
        </a>
      </div>
    </div>
  </div>
</template>
