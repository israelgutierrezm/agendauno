import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/storage/almacen_sesion.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

void main() {
  const sesion = Sesion(
    slug: 'barberia',
    bearer: '3|abc',
    nombre: 'Beto',
    rol: 'recepcionista',
    modalidad: Modalidad.citas,
    terminologia: Terminologia(sesion: 'Cita', miembro: 'Cliente', instructor: 'Barbero'),
  );

  test('la sesión se guarda y se restaura con su modalidad y terminología', () {
    final restaurada = Sesion.desdeAlmacen(sesion.aJson())!;

    expect(restaurada.slug, 'barberia');
    expect(restaurada.bearer, '3|abc');
    expect(restaurada.rol, 'recepcionista');
    expect(restaurada.esCitas, isTrue);
    expect(restaurada.terminologia.instructor, 'Barbero');
  });

  test('una sesión guardada sin slug o token no se restaura', () {
    expect(Sesion.desdeAlmacen({'slug': 'barberia'}), isNull);
    expect(Sesion.desdeAlmacen({'slug': '', 'bearer': '3|abc'}), isNull);
  });

  test('el almacén cifrado guarda, lee y borra la sesión', () async {
    FlutterSecureStorage.setMockInitialValues({});
    final almacen = AlmacenSesion();

    expect(await almacen.leer(), isNull);
    await almacen.guardar(sesion.aJson());
    expect((await almacen.leer())?['slug'], 'barberia');
    await almacen.borrar();
    expect(await almacen.leer(), isNull);
  });

  test('lo guardado ilegible se trata como sin sesión', () async {
    FlutterSecureStorage.setMockInitialValues({'agendauno.sesion': '{no es json'});

    expect(await AlmacenSesion().leer(), isNull);
  });
}
