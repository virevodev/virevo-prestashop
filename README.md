# Virevo for PrestaShop (`virevopay`)

Module de paiement **virement instantané (Virevo)** pour PrestaShop 1.7 / 8 —
sans frais de carte. Le client est redirigé vers une page de paiement Virevo ;
la commande passe « Paiement accepté » à réception du virement via un **webhook
signé**.

## Fonctionnement
- `hookPaymentOptions` ajoute « Payer par virement instantané » au checkout.
- Le contrôleur front `payment` crée la commande en **« attente de virement »**
  (état natif `PS_OS_BANKWIRE`), appelle l'**API publique Virevo `/v1`**
  (`reference` = id de commande, `Idempotency-Key`), puis redirige vers
  `payment_url`.
- Le contrôleur front `webhook` reçoit `payment.succeeded`, **vérifie la
  signature HMAC** (anti-rejeu) et passe la commande à `PS_OS_PAYMENT`.

## Installation (dev)
1. Copier le dossier `virevopay/` dans `modules/` de votre PrestaShop
   (ou installer le ZIP via **Modules → Importer un module**).
2. Installer le module, puis **Configurer** :
   - mode `test` / `live`,
   - clé d'API (`vrv_test_…` / `vrv_live_…`) depuis Virevo → Développeurs,
   - secret de webhook (`whsec_…`) ; enregistrer l'URL de webhook affichée dans
     Virevo → Développeurs.

## Distribution & mises à jour (pilotes)
```bash
bash bin/build-zip.sh        # → dist/virevopay-<version>.zip (dossier racine virevopay/)
```
Le marchand l'installe via **Modules → Importer un module**.

Release automatisée : un tag `vX.Y.Z` déclenche `.github/workflows/release.yml`
qui construit le ZIP et le joint à une **release GitHub**.
```bash
git tag v0.1.0 && git push origin v0.1.0
```

## Référence API
- Guide : https://virevo.fr/developpeurs
- OpenAPI : https://app.virevo.fr/docs

## Notes / à venir
- **Les quatre événements sont traités** depuis la 0.5.0. Un paiement refusé
  passe la commande en « Erreur de paiement », une demande annulée ou expirée en
  « Annulé ». Un remboursement décidé chez Virevo passe la commande en
  « Remboursé » s'il couvre le total, et laisse dans tous les cas un message
  privé sur la commande. **Aucun avoir n'est créé automatiquement** : le module
  écoute `actionOrderSlipAdd` pour pousser les avoirs vers Virevo, en créer un
  ici renverrait un second remboursement. Le marchand garde la main.
- EUR uniquement.
- En mode test : `POST /v1/payments/{id}/simulate` passe le paiement à
  `succeeded` et déclenche le webhook → utile pour tester de bout en bout.
- Retour automatique du client après paiement : à ajouter quand l'API exposera
  `return_url` ; aujourd'hui la commande est confirmée par le webhook.

## Licence
GPLv2 or later.
