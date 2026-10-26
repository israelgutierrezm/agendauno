<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";
import { crearClientePlataforma } from "@/lib/clientePlataforma";
import { confirmar } from "@/lib/confirmar";
import { useToastStore } from "@/stores/toast";

/**
 * Un cargo manual a un negocio desde su ficha (ADR 0214): una configuración, una
 * migración o un servicio aparte, con su descripción e importe IVA incluido. Lo ve en
 * su suscripción y lo paga como su renta.
 */
const props = defineProps<{
  apiUrl: string;
  token: string;
  slug: string;
  nombre: string;
  moneda: string;
}>();
const emit = defineEmits<{ creado: [] }>();

const { t } = useI18n();
const cliente = crearClientePlataforma({});
const toast = useToastStore();

const abierto = ref(false);
const descripcion = ref("");
const importe = ref("");
const moneda = ref(props.moneda === "USD" ? "USD" : "MXN");
const enDiezDias = new Date(Date.now() + 10 * 86_400_000);
const vence = ref(enDiezDias.toISOString().slice(0, 10));
const ocupado = ref(false);

function minor(): number | null {
  const n = Number(importe.value.replace(/[$,\s]/g, ""));
  return Number.isFinite(n) && n >= 1 ? Math.round(n * 100) : null;
}

async function crear(): Promise<void> {
  const monto = minor();
  const texto = descripcion.value.trim();
  if (monto === null || texto === "" || ocupado.value) return;
  const dinero = new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda.value,
  }).format(monto / 100);
  if (
    !(await confirmar(
      t("plataformaAdmin.rentaManual.confirmarCargo", {
        monto: dinero,
        estudio: props.nombre,
        descripcion: texto,
      }),
    ))
  ) {
    return;
  }
  ocupado.value = true;
  try {
    await cliente.post(
      `${props.apiUrl}/api/v1/plataforma/estudios/${props.slug}/cargos`,
      {
        descripcion: texto,
        monto_minor: monto,
        moneda: moneda.value,
        vence_en: vence.value,
      },
      {
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${props.token}`,
        },
      },
    );
    toast.exito(t("plataformaAdmin.rentaManual.cargoCreado"));
    abierto.value = false;
    descripcion.value = "";
    importe.value = "";
    emit("creado");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}
</script>

<template>
  <div class="cmp" data-prueba="cargo-manual">
    <button
      v-if="!abierto"
      type="button"
      class="tu-btn tu-btn-secundario tu-btn-chico"
      data-prueba="abrir-cargo-manual"
      @click="abierto = true"
    >
      {{ $t("plataformaAdmin.rentaManual.nuevoCargo") }}
    </button>
    <form v-else class="cmp-form" @submit.prevent="crear">
      <label class="cmp-campo cmp-ancho">
        <span class="tu-label">{{
          $t("plataformaAdmin.rentaManual.descripcion")
        }}</span>
        <input
          v-model="descripcion"
          class="tu-input text-sm"
          maxlength="200"
          required
        />
      </label>
      <label class="cmp-campo">
        <span class="tu-label">{{
          $t("plataformaAdmin.rentaManual.montoConIva")
        }}</span>
        <input
          v-model="importe"
          class="tu-input text-sm"
          inputmode="decimal"
          required
        />
      </label>
      <label class="cmp-campo">
        <span class="tu-label">{{
          $t("plataformaAdmin.rentaManual.moneda")
        }}</span>
        <select v-model="moneda" class="tu-input text-sm">
          <option value="MXN">MXN</option>
          <option value="USD">USD</option>
        </select>
      </label>
      <label class="cmp-campo">
        <span class="tu-label">{{
          $t("plataformaAdmin.rentaManual.vence")
        }}</span>
        <input v-model="vence" type="date" class="tu-input text-sm" required />
      </label>
      <div class="cmp-botones">
        <button
          type="submit"
          class="tu-btn tu-btn-primario tu-btn-chico"
          :disabled="ocupado || minor() === null || descripcion.trim() === ''"
        >
          {{ $t("plataformaAdmin.rentaManual.crear") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          @click="abierto = false"
        >
          {{ $t("comun.cancelar") }}
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.cmp {
  margin-top: 0.5rem;
}
.cmp-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  gap: 0.5rem;
}
.cmp-campo {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 0;
}
.cmp-ancho {
  grid-column: 1 / -1;
}
.cmp-botones {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.5rem;
}
</style>
