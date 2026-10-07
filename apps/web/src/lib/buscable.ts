import { normalizar } from "@/lib/menu";

/**
 * Una opción de una lista larga con buscador (components/SelectorBuscable.vue):
 * países, zonas horarias. `grupo` agrupa la lista sin búsqueda (p. ej. «Frecuentes»)
 * y `busqueda` agrega texto con que también se encuentra (p. ej. «America/Bogota»).
 */
export interface OpcionBuscable {
  valor: string;
  etiqueta: string;
  detalle?: string;
  grupo?: string;
  busqueda?: string;
}

/**
 * Las opciones que coinciden con lo escrito: cada palabra debe aparecer (sin
 * acentos ni mayúsculas) en la etiqueta, el detalle o el texto de búsqueda. Sin
 * texto, todas. Una opción repetida (p. ej. en «Frecuentes» y en «Todos») sale una
 * sola vez al buscar.
 */
export function filtrarOpciones(
  opciones: OpcionBuscable[],
  texto: string,
): OpcionBuscable[] {
  const palabras = normalizar(texto.trim()).split(/\s+/).filter(Boolean);
  if (palabras.length === 0) {
    return opciones;
  }
  const vistas = new Set<string>();
  return opciones.filter((o) => {
    if (vistas.has(o.valor)) {
      return false;
    }
    const donde = normalizar(
      `${o.etiqueta} ${o.detalle ?? ""} ${o.busqueda ?? ""} ${o.valor}`,
    );
    const coincide = palabras.every((p) => donde.includes(p));
    if (coincide) {
      vistas.add(o.valor);
    }
    return coincide;
  });
}
