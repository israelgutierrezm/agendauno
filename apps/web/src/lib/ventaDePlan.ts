import { computed, ref, watch, type Ref } from "vue";

import { api } from "@/lib/api";
import { textoAlcance } from "@/lib/cobertura";
import { clasesDe, type Plan } from "@/lib/planes";

/**
 * Vender un plan, dicho igual en Ventas y en la venta rápida de la ficha o la
 * recepción: qué incluye, dónde vale, sus reglas, la inscripción que se sumaría y las
 * condiciones que el cliente acepta. Lo que se confirma es lo que se cobra.
 */

type Traducir = (
  llave: string,
  valores?: Record<string, unknown>,
  plural?: number,
) => string;

/** «8 clases», «Ilimitado» o «12 clases al mes». */
export function queIncluyePlan(p: Plan, t: Traducir): string {
  if (p.tipo === "membresia") {
    return p.ilimitado
      ? t("planes.resumen.ilimitado")
      : t(
          "planes.resumen.porMes",
          { n: clasesDe(p.unidades_por_ciclo) },
          clasesDe(p.unidades_por_ciclo),
        );
  }
  if (p.ilimitado) {
    return t("planes.resumen.ilimitado");
  }
  const n = clasesDe(p.creditos_incluidos);
  return t("planes.resumen.clases", { n }, n);
}

/**
 * Dónde vale y qué deja reservar, y las reglas que el cliente debe saber antes de
 * pagar (renovación, permanencia, anualidad), en líneas cortas.
 */
export function reglasDePlan(
  p: Plan,
  t: Traducir,
  opciones: { variasSedes: boolean; esCitas: boolean },
): string[] {
  const lineas: string[] = [];
  const alcance = textoAlcance(
    { ...p, aplica_a: (p.ofertas ?? []).map((o) => o.nombre) },
    t,
    opciones,
  );
  if (alcance) {
    lineas.push(alcance);
  }
  if (p.tipo === "membresia") {
    lineas.push(t("planes.antesDeGuardar.seRenueva"));
  }
  if ((p.permanencia_meses ?? 0) > 0) {
    lineas.push(
      t(
        "planes.antesDeGuardar.permanencia",
        { n: p.permanencia_meses },
        p.permanencia_meses ?? 0,
      ),
    );
  }
  if (p.cobra_anualidad) {
    lineas.push(t("planes.antesDeGuardar.anualidad"));
  }
  return lineas;
}

/**
 * La inscripción que se sumaría a esta venta (la pide al API para esa persona) y
 * las condiciones que hay que aceptar (plan con permanencia). `alCambiar` corre al
 * cambiar de persona o de plan, antes de pedirla.
 */
export function useCuotasDeVenta(
  base: Ref<string>,
  compradorId: Ref<string>,
  producto: Ref<Plan | null>,
  alCambiar: () => void = () => {},
) {
  const inscripcion = ref(0);
  const aceptaCondiciones = ref(false);
  const pideCondiciones = computed(
    () => (producto.value?.permanencia_meses ?? 0) > 0,
  );
  let pedido = 0;
  watch(
    () => [compradorId.value, producto.value?.id] as const,
    async ([id]) => {
      const mio = ++pedido;
      inscripcion.value = 0;
      aceptaCondiciones.value = false;
      alCambiar();
      if (id === "" || !producto.value?.cobra_inscripcion) {
        return;
      }
      try {
        const { data } = await api.get<{ data: { inscripcion_minor: number } }>(
          `${base.value}/miembros/${id}/cuotas`,
        );
        if (mio === pedido) {
          inscripcion.value = data.data.inscripcion_minor;
        }
      } catch {
        // Sin el dato, la orden la suma igual si toca: el recibo lo dirá.
      }
    },
    { immediate: true },
  );
  return { inscripcion, aceptaCondiciones, pideCondiciones };
}
