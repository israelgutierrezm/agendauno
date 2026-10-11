import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth_token.dart';

/// La persona a cargo por la que actúa la cuenta (familias, ADR 0123), o null para
/// la cuenta misma. El cliente HTTP la manda como `X-Participante` solo a la cuenta
/// del alumno (`/mi/…`); el servidor decide si está autorizada. Con otra sesión
/// (otro token) vuelve a la cuenta propia.
class ParticipanteEnUso extends Notifier<String?> {
  @override
  String? build() {
    ref.watch(authTokenProvider);
    return null;
  }

  void elegir(String? personaId) => state = personaId;
}

final participanteEnUsoProvider = NotifierProvider<ParticipanteEnUso, String?>(
  ParticipanteEnUso.new,
);

/// ¿La petición va a la cuenta del alumno (`/api/v1/app/{slug}/mi/…`)?
bool esDeLaCuenta(String ruta) =>
    RegExp(r'/api/v1/app/[^/]+/mi/').hasMatch(ruta);

/// ¿El servidor dice que ya no puede actuar por esa persona?
bool esParticipanteNoAutorizado(int? estado, Object? datos) =>
    estado == 403 && datos is Map && datos['code'] == 'GUARDIAN_NOT_AUTHORIZED';
