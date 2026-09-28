/**
 * Rangos de clases actualizados por petición comercial el 26/09/2026.
 * Backend: migración 2026_09_26_200000_rangos_alumnos_saas. Citas sin cambios.
 * Es una referencia comercial, no calcula ni sustituye la facturación.
 * Al publicar otra versión en plataforma, actualizar esta referencia y sus pruebas.
 * Importes en centavos; IVA del 16 %, igual que CalcularRentaSaas.
 */
export const bandasEstudios = [
  { capacidad: "1–49 alumnos activos", subtotal: 33900 },
  { capacidad: "50–99 alumnos activos", subtotal: 63900 },
  { capacidad: "100–199 alumnos activos", subtotal: 90900 },
  { capacidad: "200–300 alumnos activos", subtotal: 178900 },
  { capacidad: "301–500 alumnos activos", subtotal: 264900 },
  { capacidad: "501–2,000 alumnos activos", subtotal: 288900 },
] as const;

export const ejemplosCitas = [
  { capacidad: "1 profesional", subtotal: 26900 },
  { capacidad: "2 profesionales", subtotal: 49500 },
  { capacidad: "3 profesionales", subtotal: 63000 },
] as const;

export function conIva(subtotal: number): number {
  return subtotal + Math.floor((subtotal * 16 + 50) / 100);
}

export function pesos(centavos: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
    minimumFractionDigits: centavos % 100 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(centavos / 100);
}
