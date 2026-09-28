/**
 * Formularios dinámicos tal como los ve una persona: la definición de cada campo con
 * el valor que ya respondió (expediente y "Mi cuenta").
 */
export type ValorCampo = string | number | boolean | null;

export interface CampoFormulario {
  id: string;
  etiqueta: string;
  tipo: "texto" | "textarea" | "numero" | "fecha" | "booleano" | "seleccion";
  obligatorio: boolean;
  opciones: string[] | null;
  valor: ValorCampo;
}

export interface FormularioPersona {
  id: string;
  nombre: string;
  descripcion: string | null;
  respondido_en: string | null;
  campos: CampoFormulario[];
}
