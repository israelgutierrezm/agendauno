import { onMounted, ref, type Ref } from "vue";

import { api } from "@/lib/api";

/**
 * ¿La plataforma agenda demos ahora? (ADR 0193 y 0204). Los botones «Agendar una demo»
 * de la landing solo se muestran si sí: encendidas y con algún horario libre. Se
 * pregunta una vez por visita a la página (los horarios y el sello los pide el
 * formulario al abrirse, al día).
 */
let consulta: Promise<boolean> | null = null;

function preguntar(): Promise<boolean> {
  consulta ??= api
    .get<{ data: { activas: boolean; dias: unknown[] } }>("/api/v1/demos")
    .then(({ data }) => data.data.activas && data.data.dias.length > 0)
    .catch(() => {
      consulta = null;
      return false;
    });
  return consulta;
}

/** `cuando`: si se pregunta (p. ej. no en la página de un negocio). */
export function useDemosActivas(
  cuando: () => boolean = () => true,
): Ref<boolean> {
  const activas = ref(false);
  onMounted(async () => {
    if (cuando()) {
      activas.value = await preguntar();
    }
  });
  return activas;
}

/** Para las pruebas: olvida la respuesta guardada. */
export function olvidarDemosActivas(): void {
  consulta = null;
}
