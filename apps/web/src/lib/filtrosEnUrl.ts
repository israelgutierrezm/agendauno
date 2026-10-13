import { computed, watch, type Ref, type WritableComputedRef } from "vue";
import { useRoute, useRouter, type LocationQueryRaw } from "vue-router";

type Campo = Ref<string> | WritableComputedRef<string>;

/**
 * Un filtro con valores fijos («» es «todos»): lo que llegue en la dirección y no sea
 * uno de ellos se ignora.
 */
export function opcionEnUrl<T extends string>(
  campo: Ref<T>,
  permitidos: readonly T[],
): WritableComputedRef<string> {
  return computed({
    get: () => campo.value,
    set: (valor: string) => {
      if ((permitidos as readonly string[]).includes(valor)) {
        campo.value = valor as T;
      }
    },
  });
}

/** Una fecha (AAAA-MM-DD): otra cosa en la dirección se ignora. */
export function fechaEnUrl(campo: Ref<string>): WritableComputedRef<string> {
  return computed({
    get: () => campo.value,
    set: (valor: string) => {
      if (valor === "" || /^\d{4}-\d{2}-\d{2}$/.test(valor)) {
        campo.value = valor;
      }
    },
  });
}

/**
 * Los filtros de un listado en la dirección (`?q=…&desde=…`): al abrir la página se
 * leen de ella y cada cambio la actualiza sin sumar al historial. Así un enlace o
 * volver atrás conserva lo que se buscaba. Solo toca sus claves (p. ej. `vista` se
 * conserva). Hay que llamarlo antes de vigilar los filtros: lo leído de la dirección
 * no dispara una carga de más. Sin router (una prueba), solo vive en memoria.
 */
export function useFiltrosEnUrl(campos: Record<string, Campo>): void {
  const route = useRoute();
  const router = useRouter();
  if (!route || !router) {
    return;
  }
  for (const [clave, campo] of Object.entries(campos)) {
    const valor = route.query[clave];
    if (typeof valor === "string") {
      campo.value = valor;
    }
  }
  watch(
    () => Object.values(campos).map((c) => c.value),
    () => {
      const query: LocationQueryRaw = { ...route.query };
      for (const [clave, campo] of Object.entries(campos)) {
        if (campo.value === "") {
          delete query[clave];
        } else {
          query[clave] = campo.value;
        }
      }
      void router.replace({ query });
    },
  );
}
