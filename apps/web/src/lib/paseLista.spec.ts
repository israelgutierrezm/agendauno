import { describe, expect, it } from "vitest";

import {
  estadoEnLista,
  filtrarLista,
  resumenLista,
  type ReservaLista,
} from "./paseLista";

const r = (
  id: string,
  persona: string,
  estado: string,
  asistencia: string | null = null,
  extra: Partial<ReservaLista> = {},
): ReservaLista => ({
  id,
  estado,
  lugar: null,
  persona_id: id,
  persona,
  primera_vez: false,
  adeudo: false,
  documentos_pendientes: 0,
  asistencia,
  ...extra,
});

const lista = [
  r("1", "Zoe Ruiz", "confirmada", "presente"),
  r("2", "Ángela Díaz", "confirmada"),
  r("3", "Beto Luna", "confirmada", "presente", { retardo: true }),
  r("4", "Carla Mena", "confirmada", "ausente"),
  r("5", "Dani Paz", "confirmada", "ausente", { asistencia_automatica: true }),
  r("6", "Eva Sol", "ofrecida"),
  r("7", "Fer Gil", "en_espera"),
  r("8", "Gael Ríos", "cancelada"),
];

describe("pase de lista", () => {
  it("un solo nombre para cada estado", () => {
    expect(lista.map(estadoEnLista)).toEqual([
      "llego",
      "por_marcar",
      "tarde",
      "no_vino",
      "no_se_presento",
      "sin_confirmar",
      "sin_confirmar",
      "sin_confirmar",
    ]);
  });

  it("resume quién ocupa lugar, quién llegó y cuántos lugares quedan", () => {
    expect(resumenLista(lista, 8)).toEqual({
      enSala: 6,
      llegaron: 2,
      porMarcar: 1,
      noVinieron: 2,
      enEspera: 1,
      libres: 2,
    });
    expect(resumenLista(lista, null).libres).toBeNull();
    expect(resumenLista(lista, 4).libres).toBe(0);
  });

  it("en orden alfabético fijo, con filtro y búsqueda sin acentos", () => {
    expect(filtrarLista(lista, "todos").map((x) => x.persona)).toEqual([
      "Ángela Díaz",
      "Beto Luna",
      "Carla Mena",
      "Dani Paz",
      "Eva Sol",
      "Zoe Ruiz",
    ]);
    expect(filtrarLista(lista, "llegaron").map((x) => x.id)).toEqual([
      "3",
      "1",
    ]);
    expect(filtrarLista(lista, "no_vinieron").map((x) => x.id)).toEqual([
      "4",
      "5",
    ]);
    // Por marcar incluye a quien aún no tiene su lugar firme.
    expect(filtrarLista(lista, "por_marcar").map((x) => x.id)).toEqual([
      "2",
      "6",
    ]);
    expect(filtrarLista(lista, "todos", "angela").map((x) => x.id)).toEqual([
      "2",
    ]);
  });
});
