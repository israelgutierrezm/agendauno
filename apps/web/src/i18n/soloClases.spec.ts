import { afterEach, describe, expect, it } from "vitest";

import { aplicarTerminologia, i18n, SOLO_CLASES } from "@/i18n";
import { POLITICAS } from "@/lib/acceso";

/*
| En un negocio de citas, los textos de pantallas que solo existen en clases no se
| adaptan: no aparecen «citas grabadas» ni «importar citas» donde no las hay. En uno de
| clases sí se adaptan a su término.
*/

const t = (llave: string) => i18n.global.t(llave);
afterEach(() => aplicarTerminologia(null));

describe("textos de pantallas solo de clases", () => {
  it("en citas dicen «clases»; lo demás sí habla como el negocio", () => {
    aplicarTerminologia(
      { sesion: "Cita", miembro: "Cliente", instructor: "Barbero" },
      "citas",
    );
    expect(t("videos.mios.subtitulo")).toBe(
      "Clases grabadas y rutinas de tu estudio.",
    );
    expect(t("agenda.nuevaClase")).toBe("Nueva cita");
  });

  it("en clases se adaptan a su término", () => {
    aplicarTerminologia(
      { sesion: "Sesión", miembro: "Socio", instructor: "Coach" },
      "clases",
    );
    expect(t("videos.mios.subtitulo")).toBe(
      "Sesiones grabadas y rutinas de tu estudio.",
    );
  });

  it("las secciones marcadas son de pantallas que solo existen en clases", () => {
    // Su pantalla está en la política de acceso con la modalidad de clases.
    const deClases = new Map([
      ["videos", "videos"],
      ["importarClases", "importar-clases"],
      ["importarReservas", "importar-reservas"],
    ]);
    for (const seccion of SOLO_CLASES) {
      const ruta = deClases.get(seccion);
      expect(ruta, seccion).toBeDefined();
      expect(POLITICAS[ruta!]?.modalidad, seccion).toBe("clases");
    }
  });
});
