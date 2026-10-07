import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';

/// Lo de Mi cuenta es de UNA sesión: al cambiar de cuenta (salir y entrar otra
/// persona en el mismo teléfono) no queda nada de la anterior aunque la carga
/// nueva falle, y lo que responda tarde la sesión anterior no se escribe.
/// Datos sintéticos.

const ana = Sesion(
  slug: 'demo',
  bearer: 'token-ana',
  nombre: 'Ana',
  rol: 'miembro',
);
const beto = Sesion(
  slug: 'otro',
  bearer: 'token-beto',
  nombre: 'Beto',
  rol: 'miembro',
);

class _Sesion extends SesionController {
  @override
  Sesion? build() => ana;

  void poner(Sesion? s) => state = s;
}

/// Repositorio falso: a Ana le responde su reserva; a Beto le falla la red.
class _Repo extends CuentaRepository {
  _Repo(this.bearer, {this.compra}) : super(Dio(), 'x');

  final String bearer;
  final Completer<void>? compra;

  @override
  Future<MiCuenta> cargar({required bool conClases}) async {
    if (bearer != 'token-ana') {
      throw DioException(requestOptions: RequestOptions(path: '/mi/perfil'));
    }
    return const MiCuenta(
      derechos: [],
      reservas: [
        ReservaMiembro(
          id: 'r1',
          estado: 'confirmada',
          tipo: TipoSesion.clase,
          oferta: 'Pole de Ana',
        ),
      ],
      clases: [],
    );
  }

  @override
  Future<void> comprar(String productoId) => compra?.future ?? Future.value();
}

ProviderContainer contenedor({Completer<void>? compra}) {
  final c = ProviderContainer(
    overrides: [
      sesionProvider.overrideWith(_Sesion.new),
      cuentaRepositoryProvider.overrideWith((ref) {
        final s = ref.watch(sesionProvider);
        return s == null ? null : _Repo(s.bearer, compra: compra);
      }),
    ],
    // Sin reintentos automáticos: la prueba decide cuándo se vuelve a pedir.
    retry: (_, _) => null,
  );
  addTearDown(c.dispose);
  return c;
}

void ponerSesion(ProviderContainer c, Sesion? s) =>
    (c.read(sesionProvider.notifier) as _Sesion).poner(s);

/// Deja pasar lo pendiente (el descarte de lo que ya no escucha nadie).
Future<void> pausa() => Future<void>.delayed(const Duration(milliseconds: 10));

void main() {
  test(
    'si falla la carga de la nueva sesión no quedan datos de la anterior',
    () async {
      final c = contenedor();
      var pantalla = c.listen(cuentaProvider, (_, _) {});
      final deAna = await c.read(cuentaProvider.future);
      expect(deAna.reservas.single.oferta, 'Pole de Ana');

      // Ana sale (se cierra su portal) y entra Beto; su carga falla.
      pantalla.close();
      ponerSesion(c, null);
      await pausa();
      ponerSesion(c, beto);
      pantalla = c.listen(cuentaProvider, (_, _) {});
      await expectLater(
        c.read(cuentaProvider.future),
        throwsA(isA<DioException>()),
      );

      final estado = c.read(cuentaProvider);
      expect(estado.hasError, isTrue);
      // Ni como "valor anterior": nada de Ana.
      expect(estado.value, isNull);
      pantalla.close();
    },
  );

  test(
    'una acción de la sesión anterior que termina tarde no escribe nada',
    () async {
      final compra = Completer<void>();
      final c = contenedor(compra: compra);
      final pantalla = c.listen(cuentaProvider, (_, _) {});
      await c.read(cuentaProvider.future);

      final accion = c.read(cuentaProvider.notifier).comprar('p1');
      // Sale antes de que responda.
      pantalla.close();
      ponerSesion(c, null);
      await pausa();
      compra.complete();

      // Termina sin tocar el estado de un portal que ya no existe.
      await expectLater(accion, completes);
    },
  );
}
