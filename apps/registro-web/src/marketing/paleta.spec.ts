import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const css = readFileSync(resolve(process.cwd(), "src/style.css"), "utf8");
function tokens(selector: string): Record<string, string> {
  const start = css.indexOf(`${selector} {`);
  const block = css.slice(start, css.indexOf("}", start));
  return Object.fromEntries(
    [...block.matchAll(/(--[\w-]+):\s*(#[\da-f]+);/gi)].map((m) => [
      m[1]!,
      m[2]!,
    ]),
  );
}
function luminancia(hex: string): number {
  let value = hex.slice(1);
  if (value.length === 3) value = [...value].map((c) => c + c).join("");
  const rgb = value
    .match(/../g)!
    .map((c) => parseInt(c, 16) / 255)
    .map((n) => (n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4));
  return rgb[0]! * 0.2126 + rgb[1]! * 0.7152 + rgb[2]! * 0.0722;
}
function contraste(a: string, b: string): number {
  const x = luminancia(a),
    y = luminancia(b);
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}
describe("legibilidad de la paleta comercial", () => {
  const claro = tokens(".tu-marketing");
  const oscuro = { ...claro, ...tokens(".dark .tu-marketing") };
  it("usa texto blanco en los botones rosas y el turquesa institucional en los negocios animados", () => {
    expect(claro["--marketing-cta-contraste"]).toBe("#ffffff");
    expect(claro["--marketing-negocios"]).toBe("#1098ad");
    for (const paleta of [claro, oscuro]) {
      for (const fondo of ["--fondo", "--superficie"]) {
        // El texto animado es grande (20 px) y negrita.
        expect(
          contraste(paleta["--marketing-negocios"]!, paleta[fondo]!),
        ).toBeGreaterThanOrEqual(3);
      }
    }
    const componente = readFileSync(
      resolve(process.cwd(), "src/components/NegociosAnimados.vue"),
      "utf8",
    );
    expect(componente).toContain("color: var(--marketing-negocios)");
  });
  it.each([
    ["claro", claro],
    ["oscuro", oscuro],
  ] as const)("mantiene contraste de texto en modo %s", (_nombre, paleta) => {
    for (const fondo of ["--marketing-cta", "--marketing-cta-hover"]) {
      expect(
        contraste(paleta["--marketing-cta-contraste"]!, paleta[fondo]!),
      ).toBeGreaterThanOrEqual(4.5);
    }
    for (const texto of [
      "--texto",
      "--texto-suave",
      "--exito-texto",
      "--enlace",
    ]) {
      for (const fondo of ["--fondo", "--superficie"]) {
        expect(
          contraste(paleta[texto]!, paleta[fondo]!),
        ).toBeGreaterThanOrEqual(4.5);
      }
    }
    // El verde Bootstrap se reserva a los dos titulares grandes, no a texto pequeño.
    for (const fondo of ["--fondo", "--superficie"]) {
      expect(
        contraste(paleta["--marketing-enfasis"]!, paleta[fondo]!),
      ).toBeGreaterThanOrEqual(3);
    }
  });
  it("usa el verde Bootstrap en énfasis grandes y un éxito legible para texto pequeño", () => {
    expect(claro["--marketing-enfasis"]).toBe("#198754");
    expect(claro["--exito"]).toBe("#198754");
    expect(oscuro["--exito"]).toBe("#198754");
    expect(contraste(claro["--exito"]!, "#ffffff")).toBeGreaterThanOrEqual(4.5);
    expect(css).toContain(".text-success {");
    const ui = readFileSync(
      resolve(process.cwd(), "src/marketing/public-ui.css"),
      "utf8",
    );
    expect(ui).toMatch(
      /\.tu-banda \.tu-titulo\s*\{\s*font-size: clamp\(1\.75rem,/,
    );
  });
  it("mantiene legibles el rosa en texto grande y las etiquetas turquesa con texto blanco", () => {
    for (const paleta of [claro, oscuro]) {
      for (const fondo of ["--fondo", "--superficie"]) {
        expect(
          contraste(paleta["--marketing-rosa"]!, paleta[fondo]!),
        ).toBeGreaterThanOrEqual(3);
      }
    }
    expect(
      contraste("#ffffff", claro["--marketing-operacion"]!),
    ).toBeGreaterThanOrEqual(4.5);
  });
});
