/**
 * ¿El archivo es de alguno de los tipos de `accept` (como en `<input type="file">`)?
 * Acepta extensiones (".csv"), tipos exactos ("image/png") y familias ("image/*").
 * Al soltar un archivo el navegador no aplica `accept`, por eso se revisa aquí. Un
 * CSV puede llegar como "application/vnd.ms-excel" en Windows: la extensión basta.
 */
export function aceptaArchivo(archivo: File, accept: string): boolean {
  const reglas = accept
    .split(",")
    .map((r) => r.trim().toLowerCase())
    .filter((r) => r !== "");
  if (reglas.length === 0) {
    return true;
  }
  const nombre = archivo.name.toLowerCase();
  const tipo = archivo.type.toLowerCase();
  return reglas.some((r) => {
    if (r.startsWith(".")) {
      return nombre.endsWith(r);
    }
    if (r.endsWith("/*")) {
      return tipo.startsWith(r.slice(0, -1));
    }
    return tipo === r;
  });
}

/**
 * Soltar un archivo fuera de una zona no debe abrirlo en la pestaña (se perdería lo
 * capturado): se cancela el comportamiento del navegador en toda la página. Las zonas
 * manejan su propio «drop» antes de que llegue aquí.
 */
export function instalarGuardaDeArrastre(ventana: Window = window): void {
  const cancelar = (e: DragEvent) => {
    const destino = e.target;
    const esEntrada =
      destino instanceof HTMLInputElement && destino.type === "file";
    // Una zona ya lo atendió, o no son archivos: nada que hacer.
    if (
      e.defaultPrevented ||
      esEntrada ||
      !e.dataTransfer?.types.includes("Files")
    ) {
      return;
    }
    e.preventDefault();
    // Fuera de una zona el cursor dice que ahí no se puede soltar.
    e.dataTransfer.dropEffect = "none";
  };
  ventana.addEventListener("dragover", cancelar);
  ventana.addEventListener("drop", cancelar);
}
