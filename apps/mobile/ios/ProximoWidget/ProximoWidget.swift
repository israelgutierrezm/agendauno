// El widget de la pantalla de inicio del cliente en iOS (WidgetKit): su próxima
// reserva o cita (qué, cuándo y dónde) y su saldo o plan. La app guarda los textos con
// home_widget en el UserDefaults del App Group; aquí solo se dibujan. De las próximas
// que guardó muestra la primera que aún no termina, y la línea de tiempo cambia sola a
// la siguiente cuando una termina. Al tocarlo, abre la app.
//
// Es el target `ProximoWidget` (Widget Extension) del proyecto de Xcode, que Runner
// copia dentro de la app (ios/WIDGET.md). Las claves son las de `ClavesWidget`
// (lib/features/widget_inicio/data/datos_widget.dart) y las mismas que lee
// WidgetProximo.kt en Android.

import SwiftUI
import WidgetKit

private enum Claves {
  static let negocio = "au_negocio"
  static let proximas = "au_proximas"
  static let planTitulo = "au_plan_titulo"
  static let plan = "au_plan"
  static let vacio = "au_vacio"
  static let accion = "au_accion"
}

/// El App Group del sabor: `AppGroupId` del Info.plist de la extensión
/// (`$(APP_GROUP_ID)`: group.com.agendauno.app o group.com.turnouno.app).
private let grupoApp =
  Bundle.main.object(forInfoDictionaryKey: "AppGroupId") as? String ?? "group.com.agendauno.app"

/// El producto del sabor: `AppProducto` del Info.plist de la extensión (`$(FLAVOR)`:
/// agendauno o turnouno). TurnoUno es de citas: el widget dice «cita», nunca
/// «reserva» ni «clase» (los mismos textos que android/.../res/values*/widget.xml).
private let esCitas =
  (Bundle.main.object(forInfoDictionaryKey: "AppProducto") as? String) == "turnouno"

private enum Textos {
  static let nombre = esCitas ? "Mi próxima cita" : "Mi próxima reserva"
  static let descripcion = esCitas ? "Tu próxima cita y tu bono." : "Tu próxima reserva y tu saldo."
  static let sinSesion =
    esCitas ? "Abre la app para ver tu próxima cita." : "Abre la app para ver tu próxima reserva."
  static let etiquetaEjemplo = esCitas ? "Tu próxima cita" : "Tu próxima clase"
  static let tituloEjemplo = esCitas ? "Cita" : "Clase"
}

/// Una reserva o cita como la guardó la app (solo lo que el cliente ya ve).
struct ProximaWidget: Decodable {
  let etiqueta: String
  let titulo: String
  let cuando: String
  let donde: String?
  let aviso: String?
  /// Cuándo termina, en milisegundos desde 1970.
  let termina: Double

  var terminaEn: Date { Date(timeIntervalSince1970: termina / 1000) }
}

struct EntradaProximo: TimelineEntry {
  let date: Date
  /// Null: no hay sesión de cliente (invita a abrir la app).
  let negocio: String?
  let proxima: ProximaWidget?
  let vacio: String
  let accion: String?
  let plan: String?
}

struct ProveedorProximo: TimelineProvider {
  private var datos: UserDefaults? { UserDefaults(suiteName: grupoApp) }

  private func proximas() -> [ProximaWidget] {
    guard let json = datos?.string(forKey: Claves.proximas),
      let bytes = json.data(using: .utf8),
      let lista = try? JSONDecoder().decode([ProximaWidget].self, from: bytes)
    else {
      return []
    }
    return lista
  }

  private func entrada(en fecha: Date) -> EntradaProximo {
    let d = datos
    let plan = d?.string(forKey: Claves.plan)
    let titulo = d?.string(forKey: Claves.planTitulo)
    return EntradaProximo(
      date: fecha,
      negocio: d?.string(forKey: Claves.negocio),
      proxima: proximas().first { $0.terminaEn > fecha },
      vacio: d?.string(forKey: Claves.vacio) ?? "Nada agendado por ahora",
      accion: d?.string(forKey: Claves.accion),
      plan: plan.map { p in titulo.map { "\($0) · \(p)" } ?? p }
    )
  }

  func placeholder(in context: Context) -> EntradaProximo {
    EntradaProximo(
      date: Date(),
      negocio: "Tu negocio",
      proxima: ProximaWidget(
        etiqueta: Textos.etiquetaEjemplo,
        titulo: Textos.tituloEjemplo,
        cuando: "lun 1 ene · 09:00",
        donde: nil,
        aviso: nil,
        termina: 0
      ),
      vacio: "Nada agendado por ahora",
      accion: nil,
      plan: nil
    )
  }

  func getSnapshot(in context: Context, completion: @escaping (EntradaProximo) -> Void) {
    completion(entrada(en: Date()))
  }

  /// Una entrada ahora y otra cuando termina cada una de las próximas: el widget pasa
  /// a la siguiente sin que se abra la app.
  func getTimeline(in context: Context, completion: @escaping (Timeline<EntradaProximo>) -> Void) {
    let ahora = Date()
    let cambios = proximas().map(\.terminaEn).filter { $0 > ahora }
    let entradas = ([ahora] + cambios).map { entrada(en: $0) }
    completion(Timeline(entries: entradas, policy: .atEnd))
  }
}

struct ProximoVista: View {
  let entrada: EntradaProximo

  var body: some View {
    VStack(alignment: .leading, spacing: 2) {
      if let negocio = entrada.negocio {
        Text(negocio).font(.caption).foregroundStyle(.secondary).lineLimit(1)
        if let p = entrada.proxima {
          Text(p.etiqueta).font(.caption).foregroundStyle(.secondary).lineLimit(1)
          Text(p.titulo).font(.headline).lineLimit(2)
          Text([p.cuando, p.donde].compactMap { $0 }.joined(separator: " · "))
            .font(.subheadline).lineLimit(2)
          if let aviso = p.aviso {
            // Estado con punto + texto.
            HStack(spacing: 6) {
              Circle().fill(Color.orange).frame(width: 8, height: 8)
              Text(aviso).font(.caption).foregroundStyle(Color.orange)
            }
          }
        } else {
          Text(entrada.vacio).font(.headline).lineLimit(2)
          if let accion = entrada.accion {
            Text(accion).font(.subheadline).foregroundStyle(.secondary).lineLimit(2)
          }
        }
        Spacer(minLength: 0)
        if let plan = entrada.plan {
          Text(plan).font(.caption).foregroundStyle(.secondary).lineLimit(1)
        }
      } else {
        Text(Textos.sinSesion).font(.headline).lineLimit(3)
        Spacer(minLength: 0)
      }
    }
    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
    .fondoDelWidget()
  }
}

extension View {
  /// El fondo del sistema: con `containerBackground` desde iOS 17 y, antes, a mano
  /// (el mínimo es el de Runner, iOS 15).
  @ViewBuilder func fondoDelWidget() -> some View {
    if #available(iOSApplicationExtension 17.0, *) {
      containerBackground(.background, for: .widget)
    } else {
      padding().background(Color(UIColor.systemBackground))
    }
  }
}

@main
struct ProximoWidget: Widget {
  /// El `kind`: el mismo que `AlmacenHomeWidget.widgetIos` en Dart.
  let kind = "ProximoWidget"

  var body: some WidgetConfiguration {
    StaticConfiguration(kind: kind, provider: ProveedorProximo()) { entrada in
      ProximoVista(entrada: entrada)
    }
    .configurationDisplayName(Textos.nombre)
    .description(Textos.descripcion)
    .supportedFamilies([.systemSmall, .systemMedium])
  }
}
