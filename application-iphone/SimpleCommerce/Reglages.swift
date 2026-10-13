import UIKit

/// Les seuls réglages de l'application. Pour changer l'adresse du portail, c'est ici et nulle part ailleurs.
enum Reglages {
    /// Adresse du portail Simple Commerce.
    static let portail = URL(string: "https://simplecommercess.com")!

    /// Première page ouverte : la liste des sites (le portail renvoie vers la connexion si besoin).
    static let depart = URL(string: "/sites?depuis=application", relativeTo: portail)!.absoluteURL

    /// Ajouté à l'identité du navigateur : le portail sait qu'il est dans l'application
    /// (il cache alors tout ce qui concerne le paiement de l'abonnement, comme Apple le demande).
    static var signature: String {
        let version = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0"
        return "SimpleCommerceApp/\(version)"
    }

    /// Les pages du portail s'ouvrent dans l'application ; tout le reste (sites des clients, Stripe…) dans Safari.
    static func estDuPortail(_ url: URL) -> Bool {
        guard let hote = url.host?.lowercased(), let attendu = portail.host?.lowercased() else { return false }
        return hote == attendu || hote == "www." + attendu
    }

    static let fond = UIColor(named: "Fond") ?? UIColor(red: 0.957, green: 0.937, blue: 0.902, alpha: 1)
    static let encre = UIColor(named: "Encre") ?? UIColor(red: 0.078, green: 0.071, blue: 0.063, alpha: 1)
}
