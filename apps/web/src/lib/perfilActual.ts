import { computed } from "vue";
import { useI18n } from "vue-i18n";

import { puedeEntrar } from "@/lib/acceso";
import { facetaActiva, nombreDeRol } from "@/lib/roles";
import { terminoParaPersona } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Quién entró, como lo muestran el menú de perfil (barra superior) y la cuenta al
 * pie del menú lateral en el teléfono: sus iniciales, el nombre de su rol activo y si
 * puede abrir la configuración del negocio.
 */
export function usePerfilActual() {
  const { t, te } = useI18n();
  const sesion = useSesionTenantStore();

  const iniciales = computed(
    () =>
      (sesion.usuario?.nombre ?? "")
        .split(" ")
        .slice(0, 2)
        .map((parte) => parte.charAt(0))
        .join("")
        .toUpperCase() || "·",
  );
  // Los roles del sistema en el género de quien entra (Alumno o Alumna, Dueña); un
  // rol propio del negocio se queda con su nombre.
  const rol = computed(() => {
    const clave = sesion.usuario?.rol ?? "";
    const nombre = nombreDeRol(
      clave,
      sesion.usuario?.roles_disponibles,
      (llave) => (te(llave) ? t(llave) : null),
    );
    const propio = sesion.usuario?.roles_disponibles?.some(
      (r) => r.clave === clave && Boolean(r.nombre),
    );
    return propio ? nombre : terminoParaPersona(nombre, sesion.usuario?.genero);
  });
  // Configuración del negocio: si alguna opción se puede abrir. Quien entra como
  // instructor no administra el negocio: su único permiso de documentos le abriría
  // una portada con «Documentos requeridos», sin nada que configurar.
  const puedeConfigurar = computed(
    () =>
      puedeEntrar("ajustes", sesion) &&
      facetaActiva(sesion.usuario) !== "instructor",
  );

  return { iniciales, rol, puedeConfigurar };
}
