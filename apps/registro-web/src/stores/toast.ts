import { defineStore } from "pinia";
import { ref } from "vue";

export type TipoToast = "exito" | "error" | "aviso" | "info";

export interface Toast {
  id: number;
  tipo: TipoToast;
  mensaje: string;
}

/**
 * Notificaciones flotantes (toasts) para dar feedback de acciones: éxito al
 * guardar, error de validación, o avisos. Se apilan arriba a la derecha y se
 * autocierran; los errores duran más para que el usuario alcance a leerlos.
 */
export const useToastStore = defineStore("toast", () => {
  const toasts = ref<Toast[]>([]);
  let secuencia = 0;

  function quitar(id: number): void {
    toasts.value = toasts.value.filter((t) => t.id !== id);
  }

  function mostrar(tipo: TipoToast, mensaje: string, duracion = 4500): number {
    const id = ++secuencia;
    toasts.value = [...toasts.value, { id, tipo, mensaje }];
    if (duracion > 0) {
      window.setTimeout(() => quitar(id), duracion);
    }
    return id;
  }

  const exito = (mensaje: string): number => mostrar("exito", mensaje);
  const error = (mensaje: string): number => mostrar("error", mensaje, 7000);
  const aviso = (mensaje: string): number => mostrar("aviso", mensaje, 6000);
  const info = (mensaje: string): number => mostrar("info", mensaje);

  return { toasts, mostrar, exito, error, aviso, info, quitar };
});
