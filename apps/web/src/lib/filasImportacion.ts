import { computed, ref, watch } from "vue";

/**
 * La vista previa de una importación (hasta miles de filas): se ve de 50 en 50 y se
 * puede dejar solo lo que hay que corregir, en vez de recorrer todo el archivo.
 */
export function useFilasImportacion<T extends { errores: string[] }>(
  filas: () => T[] | undefined,
  porPagina = 50,
) {
  const soloErrores = ref(false);
  const pagina = ref(1);
  const todas = computed(() => filas() ?? []);
  const conErrores = computed(
    () => todas.value.filter((f) => f.errores.length > 0).length,
  );
  const filtradas = computed(() =>
    soloErrores.value
      ? todas.value.filter((f) => f.errores.length > 0)
      : todas.value,
  );
  const ultimaPagina = computed(() =>
    Math.max(1, Math.ceil(filtradas.value.length / porPagina)),
  );
  const enPagina = computed(() =>
    filtradas.value.slice(
      (pagina.value - 1) * porPagina,
      pagina.value * porPagina,
    ),
  );
  // Otro archivo u otro filtro: desde la primera página. Sin errores, se ven todas.
  watch([soloErrores, todas], () => {
    pagina.value = 1;
  });
  watch(conErrores, (n) => {
    if (n === 0) soloErrores.value = false;
  });

  return {
    soloErrores,
    pagina,
    enPagina,
    ultimaPagina,
    total: computed(() => filtradas.value.length),
    todas: computed(() => todas.value.length),
    conErrores,
    porPagina,
  };
}
