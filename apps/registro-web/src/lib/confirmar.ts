import { reactive } from "vue";

/**
 * Confirmaciones dentro de la app (no la ventana básica del navegador):
 * `if (!(await confirmar("¿Quitar…?", { peligro: true }))) return;`. El diálogo se
 * monta una vez (`DialogoConfirmar` en App.vue); si no está montado (p. ej. una
 * prueba que monta solo una pantalla), se usa `window.confirm`.
 */
export interface OpcionesConfirmar {
  /** Texto del botón que confirma (por defecto, "Confirmar"). */
  aceptar?: string;
  /** Acción destructiva: el botón va en rojo. */
  peligro?: boolean;
}

export const estadoConfirmar = reactive({
  montado: false,
  abierto: false,
  mensaje: "",
  aceptar: null as string | null,
  peligro: false,
  resolver: null as ((si: boolean) => void) | null,
});

export function confirmar(
  mensaje: string,
  opciones: OpcionesConfirmar = {},
): Promise<boolean> {
  if (!estadoConfirmar.montado) {
    return Promise.resolve(window.confirm(mensaje));
  }
  // Una pregunta a la vez: si había otra abierta, cuenta como "no".
  estadoConfirmar.resolver?.(false);
  return new Promise((resolver) => {
    Object.assign(estadoConfirmar, {
      abierto: true,
      mensaje,
      aceptar: opciones.aceptar ?? null,
      peligro: opciones.peligro ?? false,
      resolver,
    });
  });
}

export function responderConfirmar(si: boolean): void {
  const resolver = estadoConfirmar.resolver;
  estadoConfirmar.abierto = false;
  estadoConfirmar.resolver = null;
  resolver?.(si);
}
