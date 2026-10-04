<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRouter, type RouteLocationRaw } from "vue-router";

import ListaRoles from "@/components/ListaRoles.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { mensajeDeError } from "@/lib/api";
import { confirmarSinGuardar } from "@/lib/cambiosPendientes";
import { nombreDeRol } from "@/lib/roles";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Panel lateral derecho de los roles (como en Acadion), en dos momentos:
 *
 * - **Al entrar** (`alEntrar`): quien tiene más de un rol en el negocio elige con
 *   cuál entra; el de la última vez viene marcado y cerrar el panel entra con ese.
 *   Luego va a `destino` (p. ej. de vuelta a agendar) o al inicio del rol elegido.
 * - **Cambiar de rol** (botón de la barra): al elegir otro, la sesión pasa a ese
 *   rol (menú, inicio y permisos) y lleva a su inicio.
 */
const props = withDefaults(
  defineProps<{
    abierto: boolean;
    alEntrar?: boolean;
    destino?: RouteLocationRaw | null;
  }>(),
  { alEntrar: false, destino: null },
);
const emit = defineEmits<{ cerrar: [] }>();

const { t, te } = useI18n();
const router = useRouter();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const aplicando = ref<string | null>(null);

const titulo = computed(() =>
  props.alEntrar
    ? t("operacion.rolActivo.titulo")
    : t("operacion.rolActivo.cambiar"),
);
const subtitulo = computed(() =>
  props.alEntrar
    ? t("operacion.rolActivo.subtitulo", {
        estudio: sesion.estudio?.nombre ?? "",
      })
    : t("operacion.rolActivo.panelSubtitulo"),
);

/** Entra con el rol elegido (al iniciar sesión). */
async function entrarCon(clave: string): Promise<void> {
  aplicando.value = clave;
  try {
    if (clave !== sesion.usuario?.rol) {
      await sesion.cambiarRol(clave);
    }
    sesion.confirmarRolInicial();
    emit("cerrar");
    await router.replace(props.destino ?? { name: sesion.rutaInicio });
  } catch (e) {
    toast.error(mensajeDeError(e, t("operacion.rolActivo.error")));
  } finally {
    aplicando.value = null;
  }
}

/** Cambia de rol con la sesión ya abierta. */
async function cambiarA(clave: string): Promise<void> {
  if (clave === sesion.usuario?.rol) {
    emit("cerrar");
    return;
  }
  if (
    !(await confirmarSinGuardar(
      t("comun.cambiosSinGuardar"),
      t("comun.salirSinGuardar"),
    ))
  ) {
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

function elegir(clave: string): void {
  if (aplicando.value !== null) return;
  void (props.alEntrar ? entrarCon(clave) : cambiarA(clave));
}

// Al entrar, cerrar el panel es entrar con el rol de la última vez.
function cerrar(): void {
  if (aplicando.value !== null) return;
  if (props.alEntrar) {
    void entrarCon(sesion.usuario?.rol ?? "");
    return;
  }
  emit("cerrar");
}
</script>

<template>
  <PanelLateral lateral :abierto="abierto" :titulo="titulo" @cerrar="cerrar">
    <div class="space-y-5 p-5" data-prueba="panel-roles">
      <p class="-mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ subtitulo }}
      </p>
      <ListaRoles
        :roles="sesion.usuario?.roles_disponibles ?? []"
        :marcado="sesion.usuario?.rol ?? null"
        :etiqueta-marca="
          alEntrar
            ? $t('operacion.rolActivo.ultimo')
            : $t('operacion.rolActivo.activo')
        "
        :aplicando="aplicando"
        @elegir="elegir"
      />
    </div>
  </PanelLateral>
</template>
