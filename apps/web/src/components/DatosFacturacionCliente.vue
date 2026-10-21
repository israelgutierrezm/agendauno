<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";

import InterruptorSiNo from "@/components/InterruptorSiNo.vue";
import { api, erroresPorCampo, mensajeDeError } from "@/lib/api";
import {
  REGIMENES_FISCALES,
  USOS_CFDI,
  regimenCorresponde,
  tipoPersonaDeRfc,
  usoCfdiCorresponde,
} from "@/lib/catalogosSat";
import { confirmar } from "@/lib/confirmar";
import { useToastStore } from "@/stores/toast";

/**
 * Los datos para facturarle a un cliente (el receptor del CFDI): en su cuenta
 * (`/mi/datos-fiscales`) o en su ficha (`/miembros/{id}/datos-fiscales`). Si el negocio
 * factura sus citas en automático, también pedirlo. Al facturar a mano, el borrador los
 * toma.
 */
interface DatosFiscales {
  rfc: string;
  nombre: string;
  codigo_postal: string;
  regimen_fiscal: string;
  uso_cfdi: string;
  email: string | null;
  factura_automatica: boolean;
}

const props = defineProps<{ url: string; propios?: boolean }>();
const { t } = useI18n();
const toast = useToastStore();

const cargado = ref(false);
const guardados = ref<DatosFiscales | null>(null);
const automaticaDisponible = ref(false);
const guardando = ref(false);
const errores = ref<Record<string, string>>({});
const form = reactive<DatosFiscales>({
  rfc: "",
  nombre: "",
  codigo_postal: "",
  regimen_fiscal: "",
  uso_cfdi: "G03",
  email: null,
  factura_automatica: false,
});

const tipo = computed(() => tipoPersonaDeRfc(form.rfc));
const regimenes = computed(() =>
  REGIMENES_FISCALES.filter((r) => regimenCorresponde(r.clave, tipo.value)),
);
const usos = computed(() =>
  USOS_CFDI.filter((u) => usoCfdiCorresponde(u.clave, tipo.value)),
);

function tomar(datos: DatosFiscales | null): void {
  guardados.value = datos;
  if (datos !== null) {
    Object.assign(form, datos);
  }
}

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{
      data: DatosFiscales | null;
      meta: { automatica_disponible: boolean };
    }>(props.url);
    tomar(data.data);
    automaticaDisponible.value = data.meta.automatica_disponible;
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    cargado.value = true;
  }
}

async function guardar(): Promise<void> {
  guardando.value = true;
  errores.value = {};
  try {
    const { data } = await api.put<{ data: DatosFiscales }>(props.url, {
      ...form,
      email: form.email?.trim() || null,
    });
    tomar(data.data);
    toast.exito(t("datosFacturacion.guardados"));
  } catch (e) {
    errores.value = erroresPorCampo(e);
    if (Object.keys(errores.value).length === 0) {
      toast.error(mensajeDeError(e));
    }
  } finally {
    guardando.value = false;
  }
}

async function borrar(): Promise<void> {
  if (
    !(await confirmar(t("datosFacturacion.confirmarBorrar"), { peligro: true }))
  ) {
    return;
  }
  try {
    await api.delete(props.url);
    guardados.value = null;
    Object.assign(form, {
      rfc: "",
      nombre: "",
      codigo_postal: "",
      regimen_fiscal: "",
      uso_cfdi: "G03",
      email: null,
      factura_automatica: false,
    });
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

onMounted(cargar);
</script>

<template>
  <form
    v-if="cargado"
    class="space-y-3"
    data-prueba="datos-facturacion"
    novalidate
    @submit.prevent="guardar"
  >
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{
        propios
          ? $t("datosFacturacion.ayudaPropios")
          : $t("datosFacturacion.ayudaFicha")
      }}
    </p>
    <div class="grid gap-3 sm:grid-cols-2">
      <div>
        <label class="tu-label" for="df-rfc">{{
          $t("datosFacturacion.rfc")
        }}</label>
        <input
          id="df-rfc"
          v-model="form.rfc"
          class="tu-input uppercase"
          maxlength="13"
          autocomplete="off"
          :aria-invalid="errores.rfc ? true : undefined"
        />
        <p v-if="errores.rfc" class="tu-error-campo">{{ errores.rfc }}</p>
      </div>
      <div>
        <label class="tu-label" for="df-cp">{{
          $t("datosFacturacion.codigoPostal")
        }}</label>
        <input
          id="df-cp"
          v-model="form.codigo_postal"
          class="tu-input"
          inputmode="numeric"
          maxlength="5"
          :aria-invalid="errores.codigo_postal ? true : undefined"
        />
        <p v-if="errores.codigo_postal" class="tu-error-campo">
          {{ errores.codigo_postal }}
        </p>
      </div>
      <div class="sm:col-span-2">
        <label class="tu-label" for="df-nombre">{{
          $t("datosFacturacion.nombre")
        }}</label>
        <input
          id="df-nombre"
          v-model="form.nombre"
          class="tu-input uppercase"
          maxlength="255"
          :aria-invalid="errores.nombre ? true : undefined"
        />
        <p class="tu-hint">{{ $t("datosFacturacion.nombreAyuda") }}</p>
        <p v-if="errores.nombre" class="tu-error-campo">{{ errores.nombre }}</p>
      </div>
      <div>
        <label class="tu-label" for="df-regimen">{{
          $t("datosFacturacion.regimen")
        }}</label>
        <select
          id="df-regimen"
          v-model="form.regimen_fiscal"
          class="tu-input"
          :aria-invalid="errores.regimen_fiscal ? true : undefined"
        >
          <option value="" disabled>{{ $t("datosFacturacion.elegir") }}</option>
          <option v-for="r in regimenes" :key="r.clave" :value="r.clave">
            {{ r.clave }} · {{ r.descripcion }}
          </option>
        </select>
        <p v-if="errores.regimen_fiscal" class="tu-error-campo">
          {{ errores.regimen_fiscal }}
        </p>
      </div>
      <div>
        <label class="tu-label" for="df-uso">{{
          $t("datosFacturacion.uso")
        }}</label>
        <select
          id="df-uso"
          v-model="form.uso_cfdi"
          class="tu-input"
          :aria-invalid="errores.uso_cfdi ? true : undefined"
        >
          <option v-for="u in usos" :key="u.clave" :value="u.clave">
            {{ u.clave }} · {{ u.descripcion }}
          </option>
        </select>
        <p v-if="errores.uso_cfdi" class="tu-error-campo">
          {{ errores.uso_cfdi }}
        </p>
      </div>
      <div class="sm:col-span-2">
        <label class="tu-label" for="df-email">{{
          $t("datosFacturacion.correo")
        }}</label>
        <input
          id="df-email"
          v-model="form.email"
          class="tu-input"
          type="email"
          autocomplete="email"
          :aria-invalid="errores.email ? true : undefined"
        />
        <p v-if="errores.email" class="tu-error-campo">{{ errores.email }}</p>
      </div>
    </div>
    <InterruptorSiNo
      v-if="automaticaDisponible"
      v-model="form.factura_automatica"
      :etiqueta="
        propios
          ? $t('datosFacturacion.automaticaPropia')
          : $t('datosFacturacion.automaticaFicha')
      "
      prueba="factura-automatica"
    />
    <div class="flex flex-wrap gap-2">
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="guardando"
        data-prueba="guardar-datos-facturacion"
      >
        {{
          guardando
            ? $t("datosFacturacion.guardando")
            : $t("datosFacturacion.guardar")
        }}
      </button>
      <button
        v-if="propios && guardados"
        type="button"
        class="tu-btn tu-btn-contorno tu-tono-peligro"
        @click="borrar"
      >
        {{ $t("datosFacturacion.borrar") }}
      </button>
    </div>
  </form>
</template>
