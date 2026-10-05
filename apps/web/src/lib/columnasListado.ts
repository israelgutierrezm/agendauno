import { ref, watch, type Ref } from "vue";

/**
 * Qué columnas ve la persona en una tabla. Se recuerda por listado en este navegador;
 * si no hay almacenamiento (o lo guardado ya no aplica), valen las predeterminadas.
 * Solo se guardan claves conocidas: una columna nueva no rompe lo guardado.
 */
export function useColumnasVisibles(
  listado: string,
  todas: readonly string[],
  predeterminadas: readonly string[],
): Ref<string[]> {
  const llave = `tu.columnas.${listado}`;
  let inicial = [...predeterminadas];
  try {
    const guardadas: unknown = JSON.parse(
      localStorage.getItem(llave) ?? "null",
    );
    if (Array.isArray(guardadas)) {
      inicial = guardadas.filter(
        (c): c is string => typeof c === "string" && todas.includes(c),
      );
    }
  } catch {
    // Sin almacenamiento o con algo ilegible: quedan las predeterminadas.
  }
  const visibles = ref<string[]>(inicial);
  watch(
    visibles,
    (valor) => {
      try {
        localStorage.setItem(llave, JSON.stringify(valor));
      } catch {
        // Ignora.
      }
    },
    { deep: true },
  );
  return visibles;
}
