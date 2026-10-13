import UIKit

/// Écran affiché quand le portail ne répond pas (pas d'Internet, serveur injoignable).
final class HorsConnexionView: UIView {
    var reessayer: (() -> Void)?

    private let titre = UILabel()
    private let texte = UILabel()

    override init(frame: CGRect) {
        super.init(frame: frame)
        backgroundColor = Reglages.fond

        let logo = UIImageView(image: UIImage(named: "Logo"))
        logo.contentMode = .scaleAspectFit
        logo.translatesAutoresizingMaskIntoConstraints = false
        logo.widthAnchor.constraint(equalToConstant: 72).isActive = true
        logo.heightAnchor.constraint(equalToConstant: 72).isActive = true
        logo.isAccessibilityElement = false

        titre.font = .systemFont(ofSize: 22, weight: .semibold)
        titre.textColor = Reglages.encre
        titre.textAlignment = .center
        titre.numberOfLines = 0

        texte.font = .preferredFont(forTextStyle: .body)
        texte.adjustsFontForContentSizeCategory = true
        texte.textColor = UIColor(red: 0.294, green: 0.271, blue: 0.243, alpha: 1)
        texte.textAlignment = .center
        texte.numberOfLines = 0

        var style = UIButton.Configuration.filled()
        style.title = "Réessayer"
        style.baseBackgroundColor = Reglages.encre
        style.baseForegroundColor = .white
        style.cornerStyle = .capsule
        style.contentInsets = NSDirectionalEdgeInsets(top: 12, leading: 28, bottom: 12, trailing: 28)
        let bouton = UIButton(configuration: style, primaryAction: UIAction { [weak self] _ in self?.reessayer?() })

        let pile = UIStackView(arrangedSubviews: [logo, titre, texte, bouton])
        pile.axis = .vertical
        pile.alignment = .center
        pile.spacing = 16
        pile.setCustomSpacing(28, after: texte)
        pile.translatesAutoresizingMaskIntoConstraints = false
        addSubview(pile)
        NSLayoutConstraint.activate([
            pile.centerYAnchor.constraint(equalTo: safeAreaLayoutGuide.centerYAnchor),
            pile.leadingAnchor.constraint(equalTo: layoutMarginsGuide.leadingAnchor, constant: 16),
            pile.trailingAnchor.constraint(equalTo: layoutMarginsGuide.trailingAnchor, constant: -16),
        ])
        afficher(horsLigne: true)
    }

    required init?(coder: NSCoder) { fatalError("init(coder:) non utilisé") }

    /// Deux messages : pas d'Internet du tout, ou Internet mais le portail ne répond pas.
    func afficher(horsLigne: Bool) {
        if horsLigne {
            titre.text = "Pas de connexion Internet"
            texte.text = "Vérifiez le Wi-Fi ou les données mobiles, puis réessayez. Rien n'a été perdu."
        } else {
            titre.text = "Simple Commerce ne répond pas"
            texte.text = "Le portail est peut-être en cours de mise à jour. Réessayez dans un instant."
        }
    }
}
