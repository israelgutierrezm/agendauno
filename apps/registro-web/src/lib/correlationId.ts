/**
 * Identificador de correlacion por pestana para trazabilidad de punta a punta.
 * Se genera una vez y se reutiliza en cada peticion (cabecera X-Correlation-ID).
 */
let correlationId: string | null = null;

export function getCorrelationId(): string {
  if (correlationId === null) {
    correlationId = crypto.randomUUID();
  }

  return correlationId;
}
