<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRouter } from "vue-router";

import ListaRoles from "@/components/ListaRoles.vue";
import { mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * «¿Cómo quieres entrar?»: al iniciar sesión, quien tiene más de un rol en el negocio
 * elige con cuál trabaja (el de la última vez viene marcado). Con uno solo no se
 * pasa por aquí.
 */
const { t } = useI18n();
const router = useRouter();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const aplicando = ref<string | null>(null);

onMounted(() => {
  if (!sesion.tieneVariosRoles) {
    void router.replace({ name: sesion.rutaInicio });
  }
});

async function elegir(clave: string): Promise<void> {
  aplicando.value = clave;
  try {
    if (clave !== sesion.usuario?.rol) {
      await sesion.cambiarRol(clave);
    }
    await router.replace({ name: sesion.rutaInicio });
  } catch (e) {
    toast.error(mensajeDeError(e, t("operacion.rolActivo.error")));
  } finally {
    aplicando.value = null;
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-lg py-6">
    <h1 class="text-2xl font-light">{{ $t("operacion.rolActivo.titulo") }}</h1>
    <p class="mt-2 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{
        $t("operacion.rolActivo.subtitulo", {
          estudio: sesion.estudio?.nombre ?? "",
        })
      }}
    </p>
    <div class="mt-6">
      <ListaRoles
        :roles="sesion.usuario?.roles_disponibles ?? []"
        :marcado="sesion.usuario?.rol ?? null"
        :etiqueta-marca="$t('operacion.rolActivo.ultimo')"
        :aplicando="aplicando"
        @elegir="elegir"
      />
    </div>
  </div>
</template>
