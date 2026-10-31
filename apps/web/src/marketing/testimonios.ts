import type { Modo } from "./modalidades";

export interface Testimonio {
  nombre: string;
  cargo: string;
  negocio: string;
  cita: string;
  /** Publicar solo con consentimiento documentado fuera del código público. */
  autorizado: boolean;
  publicado: boolean;
}

// Añade aquí testimonios reales autorizados. Sin ellos no se renderiza la sección.
export const TESTIMONIOS: Record<Modo, readonly Testimonio[]> = {
  clases: [],
  citas: [],
};

// Solo se muestran en desarrollo con ?boceto=1. Nunca se usan como prueba social real.
export const EJEMPLOS_TESTIMONIOS: Record<Modo, readonly Testimonio[]> = {
  clases: [
    {
      nombre: "Mariana · personaje ficticio",
      cargo: "Dirección",
      negocio: "Estudio de Pilates · ejemplo",
      cita: "Quiero abrir la agenda y saber quién viene, cuántos lugares quedan y qué planes están por vencer.",
      autorizado: false,
      publicado: false,
    },
    {
      nombre: "Diego · personaje ficticio",
      cargo: "Administración",
      negocio: "Academia de baile · ejemplo",
      cita: "Lo que busco es que recepción y profesores consulten la misma lista, sin volver a pasar todo por mensajes.",
      autorizado: false,
      publicado: false,
    },
    {
      nombre: "Lucía · personaje ficticio",
      cargo: "Dirección",
      negocio: "Estudio de Pole dance · ejemplo",
      cita: "Necesito que mis alumnos vean sus créditos y reserven dentro de las reglas de mi estudio.",
      autorizado: false,
      publicado: false,
    },
  ],
  citas: [
    {
      nombre: "Daniel · personaje ficticio",
      cargo: "Propietario",
      negocio: "Barbería · ejemplo",
      cita: "Quiero que cada cliente elija servicio, barbero y horario sin tener que detener un corte para contestar.",
      autorizado: false,
      publicado: false,
    },
    {
      nombre: "Elena · personaje ficticio",
      cargo: "Administración",
      negocio: "Salón de belleza · ejemplo",
      cita: "Busco tener las citas, los cobros y el trabajo de cada profesional en el mismo lugar.",
      autorizado: false,
      publicado: false,
    },
    {
      nombre: "Ana · personaje ficticio",
      cargo: "Profesional independiente",
      negocio: "Consultorio · ejemplo",
      cita: "Necesito una agenda sencilla para compartir mis horarios disponibles y revisar qué sigue en mi día.",
      autorizado: false,
      publicado: false,
    },
  ],
};

export function testimoniosPublicables(modo: Modo): readonly Testimonio[] {
  return TESTIMONIOS[modo].filter(
    (t) =>
      t.publicado &&
      t.autorizado &&
      t.cita.trim() &&
      t.nombre.trim() &&
      t.negocio.trim(),
  );
}
