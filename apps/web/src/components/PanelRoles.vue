<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRouter } from "vue-router";

import ListaRoles from "@/components/ListaRoles.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { mensajeDeError } from "@/lib/api";
import { nombreDeRol } from "@/lib/roles";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Panel lateral «Cambiar de rol» (al estilo de Acadion): los roles de la persona en
 * el negocio; al elegir otro, la sesión pasa a ese rol (menú, inicio y permisos) y
 * lleva a su inicio.
 */
defineProps<{ abierto: boolean }>();
const emit = defineEmits<{ cerrar: [] }>();

const { t, te } = useI18n();
const router = useRouter();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const aplicando = ref<string | null>(null);

async function elegir(clave: string): Promise<void> {
  if (clave === sesion.usuario?.rol) {
    emit("cerrar");
    return;
  }
  aplicando.value = clave;
  try {
    await sesion.cambiarRol(clave);
    emit("cerrar");
    await router.push({ name: sesion.rutaInicio });
    const nombre = nombreDeRol(
      clave,
      sesion.usuario?.roles_disponibles,
      (llave) => (te(llave) ? t(llave) : null),
    );
    toast.exito(t("operacion.rolActivo.cambiado", { rol: nombre }));
  } catch (e) {
    toast.error(mensajeDeError(e, t("operacion.rolActivo.error")));
  } finally {
    aplicando.value = null;
  }
}
</script>

<template>
  <PanelLateral
    :abierto="abierto"
    :titulo="$t('operacion.rolActivo.cambiar')"
    @cerrar="emit('cerrar')"
  >
    <div class="space-y-5 p-5">
      <p class="-mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("operacion.rolActivo.panelSubtitulo") }}
      </p>
      <ListaRoles
        :roles="sesion.usuario?.roles_disponibles ?? []"
        :marcado="sesion.usuario?.rol ?? null"
        :etiqueta-marca="$t('operacion.rolActivo.activo')"
        :aplicando="aplicando"
        @elegir="elegir"
      />
    </div>
  </PanelLateral>
</template>
