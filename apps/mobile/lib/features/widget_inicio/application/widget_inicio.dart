import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:home_widget/home_widget.dart';

import '../../../core/config/producto_app.dart';
import '../../../core/network/participante_en_uso.dart';
import '../../auth/application/sesion_controller.dart';
import '../../cuenta/data/cuenta_models.dart';
import '../data/datos_widget.dart';

/// Dónde quedan los datos del widget de inicio. En el teléfono, home_widget; en
/// las pruebas, uno en memoria.
abstract interface class AlmacenWidget {
  /// Guarda las claves (null borra) y pide al widget que se vuelva a dibujar.
  Future<void> guardar(Map<String, String?> claves);
}

/// home_widget: en Android, las preferencias que lee `WidgetProximo`; en iOS, el
/// UserDefaults del App Group que comparte con la Widget Extension (ver
/// `ios/WIDGET.md`). Fuera de Android e iOS no hace nada; si falla, la app sigue.
class AlmacenHomeWidget implements AlmacenWidget {
  /// El proveedor de Android, con su paquete (el `namespace`, igual en los dos
  /// sabores aunque cada uno tenga otro `applicationId`).
  static const proveedorAndroid = 'com.agendauno.app.WidgetProximo';

  /// El `kind` del widget de iOS.
  static const widgetIos = 'ProximoWidget';

  /// El App Group de iOS de esta app: `group.` + su id (el de TurnoUno o el de
  /// AgendaUno).
  static String get grupoApp => 'group.${ProductoApp.actual.idAndroid}';

  bool _grupoListo = false;

  static bool get _esTelefono =>
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.android ||
          defaultTargetPlatform == TargetPlatform.iOS);

  @override
  Future<void> guardar(Map<String, String?> claves) async {
    if (!_esTelefono) {
      return;
    }
    try {
      if (!_grupoListo) {
        await HomeWidget.setAppGroupId(grupoApp);
        _grupoListo = true;
      }
      for (final c in claves.entries) {
        await HomeWidget.saveWidgetData<String>(c.key, c.value);
      }
      await HomeWidget.updateWidget(
        qualifiedAndroidName: proveedorAndroid,
        iOSName: widgetIos,
      );
    } on PlatformException {
      // Sin el widget (o sin App Group en iOS) la app sigue igual.
    } on MissingPluginException {
      // En pruebas o en una plataforma sin el plugin.
    }
  }
}

/// El widget de inicio del cliente: lo próximo (su siguiente reserva o cita) y su
/// saldo, con lo que la app ya cargó de su cuenta. Solo escribe cuando cambia y
/// en orden: limpiar al salir y mostrar la cuenta nueva no se mezclan.
class WidgetInicio {
  WidgetInicio(this._almacen);

  final AlmacenWidget _almacen;
  String? _ultimo;
  Future<void> _cola = Future.value();

  /// Lo que mostrará el widget con esta cuenta.
  Future<void> mostrar(DatosWidgetInicio datos) => _guardar(datos.aClaves());

  /// Sin sesión de cliente (salió, cambió de rol o de negocio): el widget ya no
  /// muestra nada de la cuenta anterior.
  Future<void> limpiar() => _guardar(DatosWidgetInicio.sinSesion);

  Future<void> _guardar(Map<String, String?> claves) {
    final firma = jsonEncode(claves);
    if (firma == _ultimo) {
      return _cola;
    }
    _ultimo = firma;
    // Si una escritura falla, las siguientes siguen (el widget no es crítico).
    return _cola = _cola
        .then((_) => _almacen.guardar(claves))
        .onError<Object>((_, _) {});
  }
}

final almacenWidgetProvider = Provider<AlmacenWidget>(
  (ref) => AlmacenHomeWidget(),
);

final widgetInicioProvider = Provider<WidgetInicio>(
  (ref) => WidgetInicio(ref.watch(almacenWidgetProvider)),
);

/// Desde Mi cuenta: cada vez que su cuenta termina de cargar (al abrir la app, al
/// volver a ella, tras reservar, cancelar o pagar, al llegar un aviso) el widget
/// queda con lo mismo que su Inicio. Solo con su propia cuenta: la de una persona
/// a su cargo no se pone en su pantalla de inicio.
void actualizarWidgetInicio(WidgetRef ref, AsyncValue<MiCuenta> estado) {
  final sesion = ref.read(sesionProvider);
  final cuenta = estado.value;
  if (estado.isLoading ||
      estado.hasError ||
      cuenta == null ||
      sesion == null ||
      !sesion.esMiembro ||
      ref.read(participanteEnUsoProvider) != null) {
    return;
  }
  ref
      .read(widgetInicioProvider)
      .mostrar(
        DatosWidgetInicio.desdeCuenta(cuenta, sesion, ahora: DateTime.now()),
      );
}
