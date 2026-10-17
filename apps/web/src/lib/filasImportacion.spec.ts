import { describe, expect, it } from "vitest";
import { nextTick, ref } from "vue";

import { useFilasImportacion } from "./filasImportacion";

/*
| La vista previa de una importación de 50 en 50, con la opción de ver solo lo que hay
| que corregir.
*/

const fila = (n: number, conError: boolean) => ({
  fila: n,
  errores: conError ? ["Falta el nombre"] : [],
});

describe("filas de una importación", () => {
  it("van de 50 en 50 y se filtran a las que tienen errores", async () => {
    const filas = ref(
      Array.from({ length: 120 }, (_, i) => fila(i + 1, (i + 1) % 10 === 0)),
    );
    const f = useFilasImportacion(() => filas.value);

    expect(f.enPagina.value).toHaveLength(50);
    expect(f.ultimaPagina.value).toBe(3);
    expect(f.conErrores.value).toBe(12);

    f.pagina.value = 3;
    expect(f.enPagina.value.map((x) => x.fila)[0]).toBe(101);

    // Solo las que hay que corregir: vuelve a la primera página.
    f.soloErrores.value = true;
    await nextTick();
    expect(f.pagina.value).toBe(1);
    expect(f.total.value).toBe(12);
    expect(f.enPagina.value.every((x) => x.errores.length > 0)).toBe(true);

    // Corregido el archivo (otra vista previa sin errores), se ven todas.
    filas.value = [fila(1, false)];
    await nextTick();
    expect(f.soloErrores.value).toBe(false);
    expect(f.enPagina.value).toHaveLength(1);
  });
});
