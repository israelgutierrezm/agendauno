import { computed, ref, watch, type Ref } from "vue";

import {
  useSesionTenantStore,
  type SucursalSesion,
} from "@/stores/sesionTenant";

/**
 * La sucursal con la que se trabaja en el panel. Con varias, se elige en la barra
 * superior («Todas las sucursales» por omisión) y se recuerda por usuario en este
 * navegador; con una sola, es esa y no se pregunta.
 */
const elegida = ref<string | null>(null);
let claveCargada: string | null = null;

export function useSucursales() {
  const sesion = useSesionTenantStore();
  const lista = computed<SucursalSesion[]>(
    () => sesion.usuario?.sucursales ?? [],
  );
  const clave = computed(
    () =>
      `agendauno.sucursal.${sesion.slug ?? ""}.${sesion.usuario?.ulid ?? ""}`,
  );
  // La elección de otro usuario (u otro negocio) no se arrastra.
  watch(
    clave,
    (k) => {
      if (k !== claveCargada) {
        claveCargada = k;
        elegida.value = leer(k);
      }
    },
    { immediate: true },
  );

  // La que aplica: la única, o la elegida si sigue siendo suya; null = todas.
  const fija = computed<string | null>(() => {
    if (lista.value.length === 1) {
      return lista.value[0]!.id;
    }
    return lista.value.some((s) => s.id === elegida.value)
      ? elegida.value
      : null;
  });

  return {
    lista,
    varias: computed(() => lista.value.length > 1),
    fija,
    actual: computed(
      () => lista.value.find((s) => s.id === fija.value) ?? null,
    ),
    elegir(id: string | null): void {
      elegida.value = id;
      guardar(clave.value, id);
    },
  };
}

/**
 * Para filtros y formularios con sucursal: con una sucursal fija (la elegida en la
 * barra o la única), el selector propio sobra y su valor es esa. Con «Todas», todo
 * queda como estaba.
 *
 * - `filtro`: un filtro de listado («» = todas); sigue a la sucursal de la barra.
 * - `campo`: un campo obligatorio de un formulario; toma la fija si la hay.
 */
export function useSucursalOperativa(
  opciones: { filtro?: Ref<string>; campo?: Ref<string> } = {},
) {
  const sucursales = useSucursales();
  watch(
    sucursales.fija,
    (fija) => {
      if (opciones.filtro) {
        opciones.filtro.value = fija ?? "";
      }
      if (opciones.campo && fija !== null) {
        opciones.campo.value = fija;
      }
    },
    { immediate: true },
  );

  return {
    fija: sucursales.fija,
    actual: sucursales.actual,
    mostrarSelect: computed(() => sucursales.fija.value === null),
  };
}

function leer(clave: string): string | null {
  try {
    return localStorage.getItem(clave);
  } catch {
    return null;
  }
}
function guardar(clave: string, valor: string | null): void {
  try {
    if (valor === null) {
      localStorage.removeItem(clave);
    } else {
      localStorage.setItem(clave, valor);
    }
  } catch {
    // Sin almacenamiento: se elige de nuevo al recargar.
  }
}
