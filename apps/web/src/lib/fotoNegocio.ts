/**
 * La foto que acompaña al negocio según su giro (perfil de negocio): las mismas de
 * la landing, en `public/assets/landing/disciplinas`. Sin giro conocido, la general.
 */
const FOTOS: Record<string, string> = {
  barberia: "barberia-v1.jpg",
  estetica: "estetica-v1.jpg",
  salon: "estetica-v1.jpg",
  spa: "spa-v1.webp",
  salud: "consultorios-v1.webp",
  pilates: "pilates-v1.jpg",
  pole: "pole-v1.jpg",
  yoga: "yoga-v1.jpg",
  danza: "danza-v1.jpg",
  gimnasio: "gimnasio-v1.jpg",
  natacion: "natacion-v1.jpg",
  academia: "academias-v1.jpg",
  general: "wellness-v1.webp",
};

export function fotoNegocio(perfil: string | null | undefined): string {
  const archivo = FOTOS[perfil ?? ""] ?? FOTOS.general;
  return `/assets/landing/disciplinas/${archivo}`;
}
