import UIKit
import WebKit

/// L'écran unique de l'application : le portail Simple Commerce, avec ce qu'un téléphone sait faire en plus
/// (appareil photo pour les produits, impression des factures, partage des fichiers, écran hors connexion,
/// tirer vers le bas pour actualiser).
final class PortailViewController: UIViewController {
    private var webView: WKWebView!
    private let barre = UIProgressView(progressViewStyle: .bar)
    private let horsConnexion = HorsConnexionView()
    private let actualiser = UIRefreshControl()
    private var progression: NSKeyValueObservation?
    private var telechargements: [WKDownload: URL] = [:]
    private var derniereAdresse: URL?

    override var preferredStatusBarStyle: UIStatusBarStyle { .darkContent }

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = Reglages.fond

        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .default() // garde la connexion (« Rester connecté ») d'une ouverture à l'autre
        configuration.applicationNameForUserAgent = "Version/18.0 Mobile/15E148 Safari/604.1 " + Reglages.signature
        configuration.allowsInlineMediaPlayback = true
        // Le bouton « Imprimer » des factures appelle window.print(), qui ne fait rien dans une application : on le relaie.
        configuration.userContentController.add(RelaisFaible(self), name: "imprimer")
        configuration.userContentController.addUserScript(WKUserScript(
            source: "window.print = function () { window.webkit.messageHandlers.imprimer.postMessage(null); };",
            injectionTime: .atDocumentStart, forMainFrameOnly: true))

        webView = WKWebView(frame: .zero, configuration: configuration)
        webView.navigationDelegate = self
        webView.uiDelegate = self
        webView.allowsBackForwardNavigationGestures = true // glisser depuis le bord gauche = page précédente
        webView.isOpaque = false
        webView.backgroundColor = Reglages.fond
        webView.scrollView.backgroundColor = Reglages.fond
        webView.scrollView.refreshControl = actualiser
        actualiser.addTarget(self, action: #selector(recharger), for: .valueChanged)
        #if DEBUG
        if #available(iOS 16.4, *) { webView.isInspectable = true }
        #endif

        barre.progressTintColor = Reglages.encre
        barre.trackTintColor = .clear

        horsConnexion.isHidden = true
        horsConnexion.reessayer = { [weak self] in self?.recharger() }

        for vue in [webView!, horsConnexion, barre] as [UIView] {
            vue.translatesAutoresizingMaskIntoConstraints = false
            view.addSubview(vue)
        }
        let zone = view.safeAreaLayoutGuide
        NSLayoutConstraint.activate([
            webView.topAnchor.constraint(equalTo: zone.topAnchor),
            webView.bottomAnchor.constraint(equalTo: view.bottomAnchor),
            webView.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            webView.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            horsConnexion.topAnchor.constraint(equalTo: view.topAnchor),
            horsConnexion.bottomAnchor.constraint(equalTo: view.bottomAnchor),
            horsConnexion.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            horsConnexion.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            barre.topAnchor.constraint(equalTo: zone.topAnchor),
            barre.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            barre.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            barre.heightAnchor.constraint(equalToConstant: 2),
        ])

        progression = webView.observe(\.estimatedProgress, options: [.new]) { [weak self] vue, _ in
            guard let self else { return }
            self.barre.setProgress(Float(vue.estimatedProgress), animated: true)
            self.barre.isHidden = vue.estimatedProgress >= 1
        }

        webView.load(URLRequest(url: Reglages.depart))
    }

    @objc private func recharger() {
        horsConnexion.isHidden = true
        if webView.url == nil || webView.url?.absoluteString == "about:blank" {
            webView.load(URLRequest(url: derniereAdresse ?? Reglages.depart))
        } else {
            webView.reload()
        }
    }

    private func ouvrirDansSafari(_ url: URL) {
        UIApplication.shared.open(url)
    }

    private func montrerErreur(_ erreur: Error) {
        actualiser.endRefreshing()
        let e = erreur as NSError
        // Annulations normales : nouvelle page demandée avant la fin, ou page transformée en téléchargement.
        if e.code == NSURLErrorCancelled || (e.domain == "WebKitErrorDomain" && e.code == 102) { return }
        let horsLigne = e.domain == NSURLErrorDomain && [NSURLErrorNotConnectedToInternet, NSURLErrorNetworkConnectionLost, NSURLErrorDataNotAllowed, NSURLErrorInternationalRoamingOff].contains(e.code)
        horsConnexion.afficher(horsLigne: horsLigne)
        horsConnexion.isHidden = false
    }

    private func partager(_ fichier: URL) {
        let feuille = UIActivityViewController(activityItems: [fichier], applicationActivities: nil)
        feuille.popoverPresentationController?.sourceView = view
        feuille.popoverPresentationController?.sourceRect = CGRect(x: view.bounds.midX, y: view.bounds.midY, width: 0, height: 0)
        present(feuille, animated: true)
    }

    fileprivate func imprimer() {
        let impression = UIPrintInteractionController.shared
        let infos = UIPrintInfo(dictionary: nil)
        infos.outputType = .general
        infos.jobName = webView.title ?? "Simple Commerce"
        impression.printInfo = infos
        impression.printFormatter = webView.viewPrintFormatter()
        impression.present(animated: true)
    }

    private func alerte(_ message: String, boutons: [UIAlertAction]) {
        let a = UIAlertController(title: nil, message: message, preferredStyle: .alert)
        boutons.forEach(a.addAction)
        present(a, animated: true)
    }
}

// MARK: - Navigation

extension PortailViewController: WKNavigationDelegate {
    func webView(_ webView: WKWebView, decidePolicyFor action: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
        guard let url = action.request.url, let schema = url.scheme?.lowercased() else { return decisionHandler(.cancel) }
        if action.shouldPerformDownload { return decisionHandler(.download) }
        switch schema {
        case "http", "https":
            // Les aperçus (le site du client affiché dans un cadre) se chargent où qu'ils soient hébergés.
            if let cadre = action.targetFrame, !cadre.isMainFrame { return decisionHandler(.allow) }
            if Reglages.estDuPortail(url) { return decisionHandler(.allow) }
            ouvrirDansSafari(url) // site du client, Stripe, aide externe…
            decisionHandler(.cancel)
        case "mailto", "tel", "sms":
            ouvrirDansSafari(url)
            decisionHandler(.cancel)
        case "about", "blob", "data":
            decisionHandler(.allow)
        default:
            decisionHandler(.cancel)
        }
    }

    func webView(_ webView: WKWebView, decidePolicyFor reponse: WKNavigationResponse, decisionHandler: @escaping (WKNavigationResponsePolicy) -> Void) {
        let disposition = (reponse.response as? HTTPURLResponse)?.value(forHTTPHeaderField: "Content-Disposition")?.lowercased() ?? ""
        if disposition.hasPrefix("attachment") || !reponse.canShowMIMEType {
            return decisionHandler(.download) // sauvegardes, export des commandes… : proposés au partage
        }
        decisionHandler(.allow)
    }

    func webView(_ webView: WKWebView, navigationAction: WKNavigationAction, didBecome download: WKDownload) {
        download.delegate = self
    }

    func webView(_ webView: WKWebView, navigationResponse: WKNavigationResponse, didBecome download: WKDownload) {
        download.delegate = self
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        actualiser.endRefreshing()
        horsConnexion.isHidden = true
        if let url = webView.url, Reglages.estDuPortail(url) { derniereAdresse = url }
    }

    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
        montrerErreur(error)
    }

    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
        montrerErreur(error)
    }

    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) {
        webView.reload() // le moteur d'affichage a été arrêté par iOS (mémoire) : on recharge simplement la page
    }
}

// MARK: - Fenêtres, alertes et confirmations du portail

extension PortailViewController: WKUIDelegate {
    /// Liens « nouvelle fenêtre » : le portail reste dans l'application, le reste part dans Safari.
    func webView(_ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration, for action: WKNavigationAction, windowFeatures: WKWindowFeatures) -> WKWebView? {
        if let url = action.request.url {
            if Reglages.estDuPortail(url) { webView.load(action.request) } else { ouvrirDansSafari(url) }
        }
        return nil
    }

    func webView(_ webView: WKWebView, runJavaScriptAlertPanelWithMessage message: String, initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping () -> Void) {
        alerte(message, boutons: [UIAlertAction(title: "OK", style: .default) { _ in completionHandler() }])
    }

    /// Sans cela, les « Êtes-vous sûr ? » du portail (supprimer, annuler…) répondraient toujours « non ».
    func webView(_ webView: WKWebView, runJavaScriptConfirmPanelWithMessage message: String, initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping (Bool) -> Void) {
        alerte(message, boutons: [
            UIAlertAction(title: "Annuler", style: .cancel) { _ in completionHandler(false) },
            UIAlertAction(title: "OK", style: .default) { _ in completionHandler(true) },
        ])
    }

    func webView(_ webView: WKWebView, runJavaScriptTextInputPanelWithPrompt prompt: String, defaultText: String?, initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping (String?) -> Void) {
        let a = UIAlertController(title: nil, message: prompt, preferredStyle: .alert)
        a.addTextField { $0.text = defaultText }
        a.addAction(UIAlertAction(title: "Annuler", style: .cancel) { _ in completionHandler(nil) })
        a.addAction(UIAlertAction(title: "OK", style: .default) { _ in completionHandler(a.textFields?.first?.text) })
        present(a, animated: true)
    }
}

// MARK: - Téléchargements (sauvegardes, export des commandes, factures en PDF)

extension PortailViewController: WKDownloadDelegate {
    func download(_ download: WKDownload, decideDestinationUsing response: URLResponse, suggestedFilename: String, completionHandler: @escaping (URL?) -> Void) {
        let dossier = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString, isDirectory: true)
        do {
            try FileManager.default.createDirectory(at: dossier, withIntermediateDirectories: true)
        } catch {
            return completionHandler(nil)
        }
        let nom = suggestedFilename.isEmpty ? "fichier" : suggestedFilename
        let cible = dossier.appendingPathComponent(nom)
        telechargements[download] = cible
        completionHandler(cible)
    }

    func downloadDidFinish(_ download: WKDownload) {
        guard let fichier = telechargements.removeValue(forKey: download) else { return }
        partager(fichier)
    }

    func download(_ download: WKDownload, didFailWithError error: Error, resumeData: Data?) {
        telechargements.removeValue(forKey: download)
        alerte("Le fichier n'a pas pu être téléchargé. Vérifiez votre connexion, puis réessayez.", boutons: [UIAlertAction(title: "OK", style: .default)])
    }
}

// MARK: - Messages envoyés par la page

/// Intermédiaire « faible » : sans lui, la page garderait l'écran en mémoire pour toujours.
private final class RelaisFaible: NSObject, WKScriptMessageHandler {
    weak var cible: PortailViewController?
    init(_ cible: PortailViewController) { self.cible = cible }

    func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage) {
        if message.name == "imprimer" { cible?.imprimer() }
    }
}
