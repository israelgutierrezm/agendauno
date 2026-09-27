/**
 * Lo que trae cada persona en el listado de Miembros con `?resumen=1`: su membresía o
 * paquete (misma regla que su resumen de Recepción), su última visita, su próxima
 * reserva y si debe.
 */
export interface ResumenTarjeta {
  membresia: {
    estado: "sin" | "vigente" | "por_vencer" | "pausada" | "vencida";
    plan: string | null;
    valido_hasta: string | null;
    pausada_hasta: string | null;
    ilimitado: boolean;
    saldo_unidades: number;
    tiene_acceso: boolean;
  };
  ultima_visita: string | null;
  proxima: {
    inicia_en: string;
    zona_horaria: string | null;
    clase: string | null;
  } | null;
  adeudo: boolean;
}
