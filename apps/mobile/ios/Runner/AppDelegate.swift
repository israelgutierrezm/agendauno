import Flutter
import PassKit
import QuickLook
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  /// Canal `agendauno/archivos` (lib/core/archivos/archivos_nativos.dart).
  private let archivos = ArchivosNativos()

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    archivos.escuchar(
      FlutterMethodChannel(
        name: "agendauno/archivos",
        binaryMessenger: engineBridge.applicationRegistrar.messenger()
      )
    )
  }
}

/// Lo que la app le pide al iPhone con bytes que bajó con su sesión: agregar el pase de
/// entrada a Apple Wallet (ADR 0195) y ver un documento (un PDF) con la vista previa
/// del sistema. Responde false si no se pudo (la app lo dice).
final class ArchivosNativos: NSObject, QLPreviewControllerDataSource {
  private var canal: FlutterMethodChannel?
  private var vistaPrevia: URL?

  func escuchar(_ canal: FlutterMethodChannel) {
    canal.setMethodCallHandler { [weak self] llamada, resultado in
      self?.atender(llamada, resultado)
    }
    self.canal = canal
  }

  private func atender(_ llamada: FlutterMethodCall, _ resultado: @escaping FlutterResult) {
    let datos = (llamada.arguments as? [String: Any])?["bytes"] as? FlutterStandardTypedData
    switch llamada.method {
    case "agregarPaseWallet":
      agregarPase(datos?.data, resultado)
    case "abrir":
      let nombre = (llamada.arguments as? [String: Any])?["nombre"] as? String
      abrir(datos?.data, nombre: nombre, resultado)
    default:
      resultado(FlutterMethodNotImplemented)
    }
  }

  /// La hoja de Apple Wallet con el pase (`.pkpass` firmado por el servidor).
  private func agregarPase(_ datos: Data?, _ resultado: @escaping FlutterResult) {
    guard let datos = datos, PKAddPassesViewController.canAddPasses(),
      let pase = try? PKPass(data: datos),
      let hoja = PKAddPassesViewController(pass: pase),
      let arriba = ArchivosNativos.pantallaDeArriba()
    else {
      resultado(false)
      return
    }
    arriba.present(hoja, animated: true)
    resultado(true)
  }

  /// Lo deja en la carpeta temporal de la app y lo muestra con la vista previa de iOS.
  private func abrir(_ datos: Data?, nombre: String?, _ resultado: @escaping FlutterResult) {
    // Solo el nombre (sin rutas): nunca se escribe fuera de la carpeta temporal.
    let limpio = ((nombre ?? "") as NSString).lastPathComponent
    let url = FileManager.default.temporaryDirectory
      .appendingPathComponent(limpio.isEmpty ? "documento" : limpio)
    guard let datos = datos, (try? datos.write(to: url, options: .atomic)) != nil,
      QLPreviewController.canPreview(url as NSURL),
      let arriba = ArchivosNativos.pantallaDeArriba()
    else {
      resultado(false)
      return
    }
    vistaPrevia = url
    let vista = QLPreviewController()
    vista.dataSource = self
    arriba.present(vista, animated: true)
    resultado(true)
  }

  func numberOfPreviewItems(in controller: QLPreviewController) -> Int {
    return vistaPrevia == nil ? 0 : 1
  }

  func previewController(_ controller: QLPreviewController, previewItemAt index: Int)
    -> QLPreviewItem
  {
    return (vistaPrevia ?? FileManager.default.temporaryDirectory) as NSURL
  }

  /// La pantalla de hasta arriba de la ventana activa, para presentar encima de ella.
  private static func pantallaDeArriba() -> UIViewController? {
    let ventana = UIApplication.shared.connectedScenes
      .compactMap { $0 as? UIWindowScene }
      .flatMap { $0.windows }
      .first { $0.isKeyWindow }
    var arriba = ventana?.rootViewController
    while let presentada = arriba?.presentedViewController {
      arriba = presentada
    }
    return arriba
  }
}
