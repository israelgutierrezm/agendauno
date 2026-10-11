/**
 * La persona a cargo por la que actúa la cuenta (familias, ADR 0123), o null para la
 * cuenta misma. El cliente HTTP la manda como `X-Participante` a la cuenta del alumno
 * (`/mi/…`); el servidor decide si está autorizada. Sin dependencias: lo leen el
 * cliente HTTP y el portal.
 */
let participante: string | null = null;

export function fijarParticipante(ulid: string | null): void {
  participante = ulid;
}

export function participanteEnUso(): string | null {
  return participante;
}
