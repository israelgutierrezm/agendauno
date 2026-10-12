/**
 * Captación del negocio (ADR 0117): Prospectos (quien todavía no es cliente) y sus
 * formularios, aparte de Miembros. Aquí los tipos que devuelve la API y cómo se arma el
 * enlace público y el código para embeber un formulario en otro sitio.
 */
import { productoDelHost } from "@/lib/producto";
import { urlCanonicaEstudio } from "@/lib/tenant";

export type EstadoProspecto =
  | "nuevo"
  | "en_seguimiento"
  | "aprobado"
  | "invitacion_enviada"
  | "convertido"
  | "descartado";

export type MotivoCierre =
  | "sin_horario"
  | "precio"
  | "sin_respuesta"
  | "no_cumple"
  | "eligio_otra"
  | "otro";

export const MOTIVOS_CIERRE: readonly MotivoCierre[] = [
  "sin_horario",
  "precio",
  "sin_respuesta",
  "no_cumple",
  "eligio_otra",
  "otro",
];

export type TipoCampo =
  "texto" | "textarea" | "numero" | "fecha" | "booleano" | "seleccion";

export const TIPOS_CAMPO: readonly TipoCampo[] = [
  "texto",
  "textarea",
  "seleccion",
  "booleano",
  "numero",
  "fecha",
];

export interface EventoProspecto {
  tipo:
    | "creado"
    | "reenvio"
    | "estado"
    | "asignado"
    | "nota"
    | "seguimiento"
    | "invitado"
    | "convertido"
    // Le escribió o le llamó (canal: whatsapp, llamada o correo; ADR 0138).
    | "contacto"
    // Su responsable no lo atendió a tiempo (ADR 0138).
    | "sin_atender";
  detalle: Record<string, unknown>;
  autor: string | null;
  en: string;
}

export interface Prospecto {
  id: string;
  nombre: string;
  email: string | null;
  telefono: string | null;
  estado: EstadoProspecto;
  origen: "formulario" | "manual";
  fuente: string | null;
  formulario: string | null;
  interes: {
    sucursal: { id: string; nombre: string } | null;
    actividad: { id: string; nombre: string } | null;
    sesion: {
      id: string;
      clase: string | null;
      inicia_en: string;
      zona_horaria: string;
    } | null;
    producto: { id: string; nombre: string } | null;
  };
  responsable: { id: string; nombre: string } | null;
  proximo_seguimiento_en: string | null;
  primer_contacto_en: string | null;
  // Desde cuándo nadie lo atiende a tiempo (ADR 0138).
  sin_atender_desde?: string | null;
  motivo_cierre: MotivoCierre | null;
  comentario_cierre: string | null;
  persona: { id: string; nombre: string } | null;
  convertido_en: string | null;
  creado_en: string;
  respuestas: { campo: string; etiqueta: string; valor: unknown }[] | null;
  eventos: EventoProspecto[] | null;
  // De dónde llegó (ADR 0142), solo en el detalle: la campaña acreditada y su primer
  // origen; null = sin atribución.
  campana?: CampanaProspecto | null;
}

export interface ToqueCampana {
  fuente?: string | null;
  medio?: string | null;
  campana?: string | null;
  referente?: string | null;
}

export interface CampanaProspecto extends ToqueCampana {
  primer_origen: ToqueCampana | null;
}

/** «octubre · instagram / social», o el sitio de donde llegó. */
export function textoCampana(toque: ToqueCampana): string {
  const fuente = [toque.fuente, toque.medio].filter(Boolean).join(" / ");
  const partes = [toque.campana, fuente].filter(Boolean);
  return partes.length > 0 ? partes.join(" · ") : (toque.referente ?? "");
}

export interface CampoCaptacion {
  id?: string | null;
  etiqueta: string;
  tipo: TipoCampo;
  obligatorio: boolean;
  opciones: string[] | null;
}

export interface FormularioCaptacion {
  id: string;
  nombre: string;
  titulo: string;
  descripcion: string | null;
  mensaje_confirmacion: string | null;
  pide_telefono: boolean;
  telefono_obligatorio: boolean;
  predeterminado: boolean;
  activo: boolean;
  campos: CampoCaptacion[];
  prospectos?: number;
}

/** El formulario como lo ve quien lo llena (sin datos internos). */
export interface FormularioPublico {
  id: string;
  titulo: string;
  descripcion: string | null;
  pide_telefono: boolean;
  telefono_obligatorio: boolean;
  campos: CampoCaptacion[];
  aviso: { version: number } | null;
  negocio: { nombre: string; slug: string } | null;
}

/** ¿Sigue abierto? (convertido y descartado ya no piden seguimiento). */
export function abierto(estado: EstadoProspecto): boolean {
  return estado !== "convertido" && estado !== "descartado";
}

/** Ruta del formulario público dentro de la aplicación. */
export function rutaFormulario(slug: string, id: string): string {
  return `/f/${encodeURIComponent(slug)}/${encodeURIComponent(id)}`;
}

/**
 * Enlace del formulario para compartir: en el subdominio del negocio en producción; en
 * desarrollo (localhost), en el mismo sitio.
 */
export function urlFormulario(
  slug: string,
  id: string,
  host: string = window.location.hostname,
  origen: string = window.location.origin,
): string {
  const ruta = rutaFormulario(slug, id);
  return productoDelHost(host) === null
    ? `${origen}${ruta}`
    : urlCanonicaEstudio(slug, ruta);
}

/**
 * Código para poner el formulario en otro sitio: un iframe a la versión sin encabezado
 * que avisa su altura (postMessage) para no mostrar barras de desplazamiento.
 */
export function codigoEmbebido(url: string, titulo: string): string {
  const src = `${url}${url.includes("?") ? "&" : "?"}embed=1`;
  const seguro = titulo.replace(/[<>"&]/g, "");
  return [
    `<iframe src="${src}" title="${seguro}" style="width:100%;border:0;min-height:520px" loading="lazy"></iframe>`,
    `<script>addEventListener("message",function(e){if(e.data&&e.data.tipo==="agendauno:altura"){document.querySelectorAll('iframe[src^="${src.split("?")[0]}"]').forEach(function(f){f.style.height=e.data.altura+"px"})}});</script>`,
  ].join("\n");
}

/** Número para wa.me (solo dígitos). */
export function whatsappDe(telefono: string | null): string | null {
  const digitos = (telefono ?? "").replace(/\D/g, "");
  return digitos.length >= 8 ? `https://wa.me/${digitos}` : null;
}
