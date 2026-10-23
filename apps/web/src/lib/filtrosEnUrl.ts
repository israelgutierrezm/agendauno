import {
  computed,
  ref,
  toValue,
  watch,
  type MaybeRefOrGetter,
  type Ref,
  type WritableComputedRef,
} from "vue";
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
  if (!route?.query || !router) {
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
      // Con su ancla: Reglas de agenda vive en `#programacion`, `#politicas`…
      void router.replace({ query, hash: route.hash });
    },
  );
}

/**
 * La pestaña de una pantalla en la dirección (`?pestana=ingresos`): recargar, volver
 * atrás o compartir el enlace abre la misma. La predeterminada no se escribe y lo que
 * llegue sin estar permitido se ignora. La clave nunca es `vista` (esa es la del menú,
 * lib/menu.ts). Cambia sin sumar al historial. Sin router (una prueba), vive en memoria.
 */
export function usePestanaEnUrl<T extends string>(
  permitidas: MaybeRefOrGetter<readonly T[]>,
  opciones: { clave?: string; predeterminada?: MaybeRefOrGetter<T> } = {},
): WritableComputedRef<T> {
  const clave = opciones.clave ?? "pestana";
  const route = useRoute() as ReturnType<typeof useRoute> | undefined;
  const router = useRouter() as ReturnType<typeof useRouter> | undefined;
  const lista = (): readonly string[] => toValue(permitidas);
  const porOmision = (): T =>
    toValue(opciones.predeterminada) ?? (lista()[0] as T);
  const valida = (v: unknown): v is T =>
    typeof v === "string" && lista().includes(v);
  const actual = ref<string>(
    valida(route?.query?.[clave]) ? String(route?.query?.[clave]) : "",
  );
  if (route?.query) {
    watch(
      () => route.query[clave],
      (v) => {
        actual.value = valida(v) ? v : "";
      },
    );
  }

  return computed({
    get: () => (valida(actual.value) ? actual.value : porOmision()),
    set: (valor: T) => {
      if (!valida(valor)) {
        return;
      }
      actual.value = valor;
      if (!route?.query || !router) {
        return;
      }
      const query: LocationQueryRaw = { ...route.query };
      if (valor === porOmision()) {
        delete query[clave];
      } else {
        query[clave] = valor;
      }
      void router.replace({ query, hash: route.hash });
    },
  });
}
