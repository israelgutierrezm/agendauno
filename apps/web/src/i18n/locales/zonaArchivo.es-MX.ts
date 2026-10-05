// Zona para subir un archivo (components/ZonaArchivo.vue): arrastrar o elegir.
export default {
  arrastra: "Arrastra el archivo aquí o",
  elige: "elígelo",
  suelta: "Suéltalo para subirlo",
  subiendo: "Subiendo…",
  cambiar: "Arrastra otro o haz clic para cambiarlo",
  tipo: "Ese tipo de archivo no se acepta aquí.",
  peso: "El archivo pesa más de lo permitido.",
  // Lo que se acepta en cada lugar (igual a lo que valida el servidor).
  formatos: {
    csv: "Un archivo CSV de hasta 2 MB",
    documento: "PDF, JPG o PNG de hasta 8 MB",
    cer: "El certificado (.cer) del SAT",
    key: "La llave privada (.key) del SAT",
  },
  csv: {
    arrastra: "Arrastra tu archivo CSV aquí o",
  },
  documento: {
    arrastra: "Arrastra el documento aquí o",
    otro: "Arrastra el nuevo documento aquí o",
  },
  imagen: {
    elige: "elígela",
    suelta: "Suéltala para reemplazar la imagen",
    sueltaNueva: "Suéltala para subirla",
  },
};
