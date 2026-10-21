import { describe, expect, it } from "vitest";

import { porCategoria, reproductorDe, type Video } from "./videos";

describe("videos del negocio", () => {
  it("YouTube y Vimeo se ven con su reproductor; lo demás, no", () => {
    expect(reproductorDe("https://www.youtube.com/watch?v=dQw4w9WgXcQ")).toBe(
      "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ",
    );
    expect(reproductorDe("https://youtu.be/dQw4w9WgXcQ")).toBe(
      "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ",
    );
    expect(reproductorDe("https://vimeo.com/76979871")).toBe(
      "https://player.vimeo.com/video/76979871",
    );
    expect(reproductorDe("https://midrive.com/video.mp4")).toBeNull();
    expect(reproductorDe("http://youtube.com/watch?v=dQw4w9WgXcQ")).toBeNull();
    expect(reproductorDe("javascript:alert(1)")).toBeNull();
    expect(reproductorDe('https://youtube.com/watch?v="><script>')).toBeNull();
  });

  it("agrupa por categoría y deja sin categoría al final", () => {
    const v = (titulo: string, categoria: string | null): Video => ({
      id: titulo,
      titulo,
      descripcion: null,
      url: null,
      duracion_minutos: null,
      categoria,
      publicado: true,
      orden: 0,
      planes: [],
    });
    const grupos = porCategoria([
      v("a", null),
      v("b", "Fuerza"),
      v("c", "Fuerza"),
      v("d", "Estiramiento"),
    ]);
    expect(grupos.map(([c, l]) => [c, l.map((x) => x.titulo)])).toEqual([
      ["Fuerza", ["b", "c"]],
      ["Estiramiento", ["d"]],
      ["", ["a"]],
    ]);
  });
});
