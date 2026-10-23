/**
 * Cómo está una fecha de cobro o de vencimiento respecto a hoy en el negocio
 * (`hoyEnNegocio`): próxima, hoy o vencida, y a cuántos días. Se calcula aquí, así que
 * vale sin importar de dónde vengan los datos (demo, importados, sin pasarela).
 */
export type SituacionFecha = "proxima" | "hoy" | "vencida";

export function situacionDeFecha(
  fecha: string,
  hoy: string,
): { situacion: SituacionFecha; dias: number } {
  const dia = (ymd: string) => Date.parse(`${ymd.slice(0, 10)}T12:00:00Z`);
  const diferencia = Math.round((dia(fecha) - dia(hoy)) / 86_400_000);
  return {
    situacion:
      diferencia > 0 ? "proxima" : diferencia === 0 ? "hoy" : "vencida",
    dias: Math.abs(diferencia),
  };
}
