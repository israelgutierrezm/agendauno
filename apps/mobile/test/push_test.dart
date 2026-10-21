import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/config/firebase_config.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/notificaciones/application/aviso_abierto.dart';
import 'package:agendauno/features/notificaciones/application/push_controller.dart';
import 'package:agendauno/features/notificaciones/data/dispositivos_repository.dart';

/// Repositorio que solo anota lo que se le pide (no hay servidor en las pruebas).
class _RepoAnotador extends DispositivosRepository {
  _RepoAnotador() : super(Dio());

  final llamadas = <String>[];

  @override
  Future<void> registrar(String slug, String token, String plataforma) async =>
      llamadas.add('registrar $slug');

  @override
  Future<void> quitar(String slug, String token) async =>
      llamadas.add('quitar $slug');
}

void main() {
  test('sin datos de Firebase al compilar, la app no tiene push', () {
    expect(FirebaseConfig.opciones, isNull);
    expect(PushController(_RepoAnotador()).disponible, isFalse);
  });

  test('sin push, iniciar sesión y cerrarla siguen funcionando', () async {
    final repo = _RepoAnotador();
    final push = PushController(repo);
    final sesion = Sesion.desdeJson('estudio-a', 'bearer', {
      'rol': 'miembro',
    }, null);

    await push.iniciar((_, _) {});
    await push.registrar(sesion);
    await push.olvidar(sesion);

    expect(repo.llamadas, isEmpty);
  });

  test('tocar una notificación lleva a lo suyo', () {
    expect(DestinoPush.de('reserva.recordatorio_24h'), DestinoPush.reservas);
    expect(DestinoPush.de('reserva.sesion_cancelada'), DestinoPush.reservas);
    expect(DestinoPush.de('orden.pagada'), DestinoPush.pagos);
    expect(DestinoPush.de('cobro.fallido'), DestinoPush.pagos);
    expect(DestinoPush.de('membresia.renovacion_proxima'), DestinoPush.pagos);
    expect(DestinoPush.de('difusion'), DestinoPush.inicio);
    // Le escribieron al negocio: el equipo abre la bandeja.
    expect(DestinoPush.de('bandeja.mensaje_recibido'), DestinoPush.mensajes);
    expect(DestinoPush.de(null), DestinoPush.inicio);
  });

  test('una notificación de otro negocio no cambia de pantalla', () {
    final c = ProviderContainer(
      overrides: [
        sesionInicialProvider.overrideWithValue(
          const Sesion(
            slug: 'estudio-a',
            bearer: 't',
            nombre: 'Ana',
            rol: 'miembro',
          ),
        ),
      ],
    );
    addTearDown(c.dispose);
    final aviso = c.read(avisoAbiertoProvider.notifier);

    aviso.abrir({'estudio': 'estudio-b', 'tipo': 'orden.pagada'});
    expect(c.read(avisoAbiertoProvider), isNull);

    aviso.abrir({'estudio': 'estudio-a', 'tipo': 'orden.pagada'});
    expect(c.read(avisoAbiertoProvider), DestinoPush.pagos);
    aviso.atendido();
    expect(c.read(avisoAbiertoProvider), isNull);
  });
}
