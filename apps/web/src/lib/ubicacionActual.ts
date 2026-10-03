import { computed, type ComputedRef } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, type RouteLocationRaw } from "vue-router";

import {
  destinoDe,
  ubicacion,
  vistasVisibles,
  type Area,
  type Vista,
} from "@/lib/menu";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

export interface Miga {
  texto: string;
  // Sin destino: es la pantalla actual o un tramo que no se abre (una categoría).
  destino?: RouteLocationRaw;
}

/** Pantallas de paso dentro de un área: su tramo final en la ruta de ubicación. */
const PASO: Record<string, string> = {
  "ficha-miembro": "nav.paso.ficha",
  "ficha-instructor": "nav.paso.ficha",
  importar: "nav.importar",
  "importar-instructores": "nav.importar",
  "importar-clases": "importarClases.titulo",
  onboarding: "onboarding.titulo",
};

/** Pantallas personales, fuera del menú: solo su título. */
const PERSONALES: Record<string, string> = {
  "mi-perfil": "miPerfil.titulo",
};

/**
 * Dónde está la persona, para la barra superior: el título del área (con su ícono)
 * y la ruta de ubicación (área › categoría › vista › paso). Sale del mismo
 * `ubicacion()` que marca el menú y las pestañas, con el término del negocio.
 */
export function useUbicacionActual(): {
  titulo: ComputedRef<string>;
  icono: ComputedRef<string | null>;
  migas: ComputedRef<Miga[]>;
} {
  const route = useRoute();
  const { t } = useI18n();
  const sesion = useSesionTenantStore();

  const actual = computed(() => ubicacion(route));
  const nombre = (x: Area | Vista): string =>
    x.termino !== undefined
      ? plural(sesion.terminologia[x.termino])
      : t(x.etiqueta);

  const titulo = computed(() => {
    if (actual.value !== null) {
      return nombre(actual.value.area);
    }
    const personal = PERSONALES[String(route.name)];
    return personal !== undefined ? t(personal) : "";
  });
  const icono = computed(() => actual.value?.area.icono ?? null);

  const migas = computed<Miga[]>(() => {
    const u = actual.value;
    if (u === null) {
      return [];
    }
    const area = nombre(u.area);
    const primera = vistasVisibles(u.area, sesion)[0];
    const lista: Miga[] = [
      { texto: area, destino: primera ? destinoDe(primera) : undefined },
    ];
    if (u.categoria !== null) {
      lista.push({ texto: t(u.categoria.etiqueta) });
    }
    const paso = PASO[String(route.name)];
    const vista = nombre(u.vista);
    // «Reportes › Reportes» no dice nada: la vista va si se llama distinto.
    if (vista !== area) {
      lista.push({
        texto: vista,
        destino: paso !== undefined ? destinoDe(u.vista) : undefined,
      });
    }
    if (paso !== undefined) {
      lista.push({ texto: t(paso) });
    }
    // El último tramo es la pantalla actual: no es enlace.
    const ultimo = lista[lista.length - 1];
    if (ultimo !== undefined) {
      ultimo.destino = undefined;
    }
    return lista;
  });

  return { titulo, icono, migas };
}
