<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRouter } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import DescargarDatosNegocio from "@/components/DescargarDatosNegocio.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { PRODUCTOS, productoActual } from "@/lib/producto";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * El negocio dado de baja (ADR 0213): desde cuándo, el día en que se borran sus datos
 * y lo que su dueño aún hace: pagar lo pendiente de su suscripción y, si la pidió él,
 * revertir la baja.
 */
const { t } = useI18n();
const router = useRouter();
const sesion = useSesionTenantStore();
const toast = useToastStore();

const baja = computed(() => sesion.estudio?.baja ?? null);
const marca = PRODUCTOS[productoActual()].nombre;
const ocupado = ref(false);
const error = ref<string | null>(null);

/** «31 de octubre de 2026» de una fecha o un instante ISO. */
function fecha(iso: string | null | undefined): string {
  if (!iso) return "";
  const [a, m, d] = iso.slice(0, 10).split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(new Date(a, m - 1, d));
}

async function revertir(): Promise<void> {
  if (
    ocupado.value ||
    !(await confirmar(
      t("bajaNegocio.confirmarRevertir", {
        negocio: sesion.estudio?.nombre ?? "",
      }),
      { tono: "aviso" },
    ))
  ) {
    return;
  }
  ocupado.value = true;
  error.value = null;
  try {
    await sesion.revertirBajaNegocio();
    toast.exito(t("bajaNegocio.revertida"));
    await router.replace({ name: sesion.rutaInicio });
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}

onMounted(() => {
  // Sin baja (ya se revirtió), su inicio de siempre.
  if (!sesion.dadoDeBaja) {
    void router.replace({ name: sesion.rutaInicio });
  }
});
</script>

<template>
  <section class="tu-pagina" data-prueba="negocio-dado-de-baja">
    <EncabezadoSeccion :titulo="$t('bajaNegocio.estadoTitulo')" />
    <div v-if="baja" class="tu-card mt-4 max-w-2xl space-y-3 p-5">
      <p>
        {{
          $t("bajaNegocio.estadoDesde", { fecha: fecha(baja.dado_de_baja_en) })
        }}
      </p>
      <p>
        {{
          $t("bajaNegocio.estadoBorrado", {
            fecha: fecha(baja.datos_borrar_en),
          })
        }}
      </p>
      <p v-if="baja.por === 'plataforma'" class="text-sm">
        {{ $t("bajaNegocio.porPlataforma", { marca }) }}
      </p>
      <AvisoTono v-if="error" tono="peligro" role="alert">{{
        error
      }}</AvisoTono>
      <div class="flex flex-wrap gap-2 pt-2">
        <RouterLink :to="{ name: 'renta' }" class="tu-btn tu-btn-secundario">
          {{ $t("bajaNegocio.verSuscripcion") }}
        </RouterLink>
        <button
          v-if="baja.puede_revertir"
          type="button"
          class="tu-btn tu-btn-primario"
          :disabled="ocupado"
          data-prueba="revertir-baja"
          @click="revertir"
        >
          {{ $t("bajaNegocio.revertir") }}
        </button>
      </div>
    </div>
    <div v-if="baja" class="tu-card mt-4 max-w-2xl p-5">
      <DescargarDatosNegocio />
    </div>
  </section>
</template>
