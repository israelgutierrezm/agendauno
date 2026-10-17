import { computed, nextTick, ref, type Ref } from "vue";
import { useI18n } from "vue-i18n";

import { erroresPorCampo, mensajeDeError } from "@/lib/api";
import { instantanea, useCambiosPendientes } from "@/lib/cambiosPendientes";
import { confirmar } from "@/lib/confirmar";
import { useToastStore } from "@/stores/toast";

/**
 * El patrón común de los formularios de alta y edición (en un panel o un diálogo):
 *
 * - El error va junto a su campo (`marcar('campo', 'id-del-error')` en el input y
 *   `errores.campo` en su mensaje) y arriba un resumen (`error`): «Revisa los campos
 *   marcados» si todo es de campos del formulario, o el mensaje del API si no. Se
 *   enfoca el primer campo marcado.
 * - Mientras guarda, el contenedor no se cierra (`:ocupado="guardando"`).
 * - Con cambios sin guardar, cerrar (la X, Escape, el fondo o «Cancelar», todos por
 *   `cerrar()`) o salir de la pantalla pregunta antes de descartarlos.
 * - Si guardar falla, lo capturado se queda tal cual.
 * - Al guardar se confirma qué quedó guardado (un aviso con el nombre de lo guardado).
 *
 * Uso: llenar el formulario, `empezar()` y abrir; `guardar(() => api.put(…), 'Promoción
 * VERANO guardada')` devuelve la respuesta o `undefined` si falló.
 */
export function useEditorFormulario(opciones: {
  abierto: Ref<boolean>;
  valor: () => unknown;
  // Los campos que muestra el formulario (para el resumen).
  campos?: string[];
  // Selector del formulario, para enfocar el primer campo marcado.
  formulario?: string;
}) {
  const { t } = useI18n();
  const toast = useToastStore();
  const foto = instantanea(opciones.valor);
  const guardando = ref(false);
  const errores = ref<Record<string, string>>({});
  const error = ref<string | null>(null);
  const sucio = computed(() => opciones.abierto.value && foto.cambio());
  useCambiosPendientes(() => sucio.value);

  /** Tras llenar el formulario (nuevo o con lo guardado): sin errores y sin cambios. */
  function empezar(): void {
    errores.value = {};
    error.value = null;
    foto.fijar();
  }

  /** Cierra si no está guardando; con cambios sin guardar, pregunta antes. */
  async function cerrar(): Promise<boolean> {
    if (guardando.value) {
      return false;
    }
    if (
      sucio.value &&
      !(await confirmar(t("comun.cambiosSinGuardar"), {
        aceptar: t("comun.salirSinGuardar"),
        peligro: true,
      }))
    ) {
      return false;
    }
    opciones.abierto.value = false;
    return true;
  }

  /** Atributos del campo marcado (para `v-bind`). */
  function marcar(
    campo: string,
    idError: string,
  ): Record<string, string | undefined> {
    return errores.value[campo]
      ? { "aria-invalid": "true", "aria-describedby": idError }
      : {};
  }

  async function guardar<R>(
    accion: () => Promise<R>,
    exito?: string | ((r: R) => string),
  ): Promise<R | undefined> {
    guardando.value = true;
    errores.value = {};
    error.value = null;
    try {
      const resultado = await accion();
      foto.fijar();
      if (exito !== undefined) {
        toast.exito(typeof exito === "function" ? exito(resultado) : exito);
      }
      return resultado;
    } catch (e) {
      // Lo capturado se queda: solo se corrige lo marcado.
      const campos = erroresPorCampo(e);
      errores.value = campos;
      const claves = Object.keys(campos);
      const deCampos = opciones.campos ?? [];
      error.value =
        claves.length > 0 && claves.every((c) => deCampos.includes(c))
          ? t("comun.revisaCampos")
          : mensajeDeError(e);
      if (opciones.formulario !== undefined) {
        await nextTick();
        document
          .querySelector<HTMLElement>(
            `${opciones.formulario} [aria-invalid="true"]`,
          )
          ?.focus();
      }
      return undefined;
    } finally {
      guardando.value = false;
    }
  }

  return {
    guardando,
    errores,
    error,
    sucio,
    empezar,
    cerrar,
    marcar,
    guardar,
  };
}
