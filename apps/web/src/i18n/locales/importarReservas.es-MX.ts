// Importar las reservas futuras de clases de otro sistema (views/ImportarReservasView.vue;
// ADR 0176): con el corte y la regla para no descontar créditos dos veces.
export default {
  titulo: "Importar reservas",
  ir: "Importar las reservas futuras",
  instrucciones:
    "Trae las clases que tus alumnos ya tenían reservadas en el otro sistema, confirmadas o en lista de espera. Cada reserva entra como si la hubieran hecho aquí: aparta su crédito, respeta el cupo y, si cancelan a tiempo, el crédito vuelve solo. La importación no le avisa nada a nadie; el recordatorio antes de cada clase, solo si lo marcas.",
  pasoAlumnos:
    "Primero importa a los alumnos (con su correo) y su horario de clases.",
  pasoSaldos:
    "Carga sus saldos de apertura antes: cada reserva confirmada aparta un crédito de su plan.",
  pasoCorte:
    "Usa la misma fecha de corte que en los saldos: se importan las clases desde ese día que aún no empiezan.",
  corte: "Fecha de corte",
  descontadas: {
    pregunta: "Los saldos que cargaste, ¿ya descontaron estas reservas?",
    no: "No: los saldos incluyen estas clases",
    noAyuda:
      "Cada reserva aparta su crédito del saldo cargado (como al reservar aquí).",
    si: "Sí: el otro sistema ya las descontó",
    siAyuda:
      "Cada reserva confirmada reintegra su crédito como apertura y lo aparta: el saldo no cambia y el estado de cuenta lo muestra.",
  },
  recordatorios: "Mandarles su recordatorio antes de cada clase",
  recordatoriosAyuda:
    "La importación nunca avisa. Apagado, tampoco reciben recordatorio antes de estas clases; encendido, reciben el de siempre, como si hubieran reservado aquí.",
  faltan:
    "Indica la fecha de corte y si los saldos ya descontaron estas reservas.",
  revisar: "Revisar archivo",
  colAlumno: "Alumno",
  colClase: "Clase",
  colCuando: "Fecha y hora",
  colQueda: "Queda",
  queda: {
    confirmada: "Confirmada",
    en_espera: "En lista de espera",
  },
  extra:
    "Pendientes (sin plan con saldo o clase llena): {pendientes} · en lista de espera: {espera} · créditos reintegrados: {reintegrados}",
  importar: "Importar 1 reserva | Importar {n} reservas",
  confirmar:
    "Se importará 1 reserva al corte del {corte}, sin avisarle a nadie. | Se importarán {n} reservas al corte del {corte}, sin avisarle a nadie.",
  confirmarReintegro:
    "Como los saldos ya las descontaron, se reintegrará 1 crédito como apertura y se apartará para su clase. | Como los saldos ya las descontaron, se reintegrarán {n} créditos como apertura y se apartarán para esas clases.",
  confirmarRecordatorios: "Recibirán su recordatorio antes de cada clase.",
  importadas: "1 reserva importada | {n} reservas importadas",
  sinAvisos:
    "No se le avisó nada a tus alumnos y no recibirán recordatorio de estas clases.",
  conRecordatorios:
    "No se les avisó de la importación; recibirán su recordatorio antes de cada clase.",
  verAgenda: "Ver la agenda",
};
