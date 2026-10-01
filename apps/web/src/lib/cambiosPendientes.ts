import { onBeforeUnmount, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { onBeforeRouteLeave } from "vue-router";

import { confirmar } from "@/lib/confirmar";

/**
 * Cambios sin guardar: un formulario dice cuándo tiene cambios y, mientras los
 * tenga, se pregunta antes de salir a otra pantalla, de cambiar de rol o de cerrar
 * o recargar la pestaña (el aviso del navegador). Moverse entre las anclas de la
 * misma pantalla (p. ej. secciones de Reglas de agenda) no pregunta.
 */
const conCambios = reactive(new Set<symbol>());
let escuchandoPestana = false;

function alCerrarPestana(e: BeforeUnloadEvent): void {
  if (conCambios.size > 0) {
    e.preventDefault();
    e.returnValue = "";
  }
}

/** ¿Algún formulario abierto tiene cambios sin guardar? */
export function hayCambiosPendientes(): boolean {
  return conCambios.size > 0;
}

/**
 * Si hay cambios sin guardar, pregunta si se sigue sin guardarlos. true = seguir.
 * Para acciones que salen del formulario sin cambiar de ruta (cambiar de rol).
 */
export async function confirmarSinGuardar(
  mensaje: string,
  aceptar: string,
): Promise<boolean> {
  if (!hayCambiosPendientes()) {
    return true;
  }
  const seguir = await confirmar(mensaje, { aceptar, peligro: true });
  if (seguir) {
    conCambios.clear();
  }
  return seguir;
}

/** Registra un formulario: `sucio` dice si tiene cambios sin guardar. */
export function useCambiosPendientes(sucio: () => boolean): void {
  const { t } = useI18n();
  const id = Symbol("formulario");
  if (!escuchandoPestana && typeof window !== "undefined") {
    window.addEventListener("beforeunload", alCerrarPestana);
    escuchandoPestana = true;
  }
  watch(
    sucio,
    (s) => {
      if (s) {
        conCambios.add(id);
      } else {
        conCambios.delete(id);
      }
    },
    { immediate: true },
  );
  onBeforeUnmount(() => conCambios.delete(id));
  onBeforeRouteLeave(async (to, from) => {
    if (!sucio() || to.path === from.path) {
      return true;
    }
    const seguir = await confirmar(t("comun.cambiosSinGuardar"), {
      aceptar: t("comun.salirSinGuardar"),
      peligro: true,
    });
    if (seguir) {
      conCambios.delete(id);
    }
    return seguir;
  });
}

/**
 * Una foto de lo guardado para comparar: `fijar()` tras cargar o guardar;
 * `cambio()` dice si lo actual ya es distinto.
 */
export function instantanea<T>(valor: () => T): {
  fijar: () => void;
  cambio: () => boolean;
} {
  const base = ref(JSON.stringify(valor()));
  return {
    fijar: () => {
      base.value = JSON.stringify(valor());
    },
    cambio: () => JSON.stringify(valor()) !== base.value,
  };
}
