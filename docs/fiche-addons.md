# Fiche PrestaShop Addons : textes à coller

> Préparé le 2026-10-22 pour la v0.6.2. Chaque bloc correspond à un champ du
> formulaire « Ajouter un produit ». Les limites de caractères d'Addons changent :
> si un champ refuse un texte, le raccourcir par la fin.
>
> Règle Addons : aucune adresse e-mail externe ni invitation à acheter ailleurs
> (signature, fiche, réponses aux clients). Le support passe par Addons. Le
> lien vers virevo.fr reste nécessaire : le module exige un compte Virevo.

## Réglages du produit

| Champ | Valeur |
|---|---|
| Type | Module |
| Catégorie | Paiement › Virement bancaire (à défaut : Paiement) |
| Prix | Gratuit |
| Compatibilité | 1.7.6 à 9.x (testé de bout en bout sur 9.0.3) |
| Langues de l'interface | Français |
| Fichier | `dist/virevopay-0.6.2.zip` |
| Logo | `virevopay/logo.png` (200 × 200) |
| Site de l'éditeur | https://virevo.fr |

---

## Français

### Nom
Virevo : paiement par virement instantané

### Accroche (courte)
Vos clients paient par virement instantané depuis leur banque. Pas de frais de carte, l'argent arrive directement sur votre IBAN.

### Description

**Encaissez sans les frais de carte.**
Virevo ajoute au tunnel de commande un moyen de paiement « Payer par virement instantané ». Le client choisit sa banque, valide le virement dans son application bancaire, et la commande passe d'elle-même en « Paiement accepté ».

**Ce que vous gagnez**
- Pas de frais de carte, pas de terminal, pas d'abonnement.
- L'argent va directement du compte du client à votre IBAN : Virevo ne détient jamais vos fonds.
- Virement SEPA instantané : irrévocable, pas de rétrofacturation (chargeback) comme avec la carte.
- Disponible dès 1 € TTC.

**Ce que fait le module**
- Crée la commande en « En attente de virement », puis redirige le client vers la page de paiement sécurisée Virevo.
- Confirme la commande par un webhook signé (HMAC-SHA256, protection contre le rejeu).
- Gère les quatre issues d'un paiement : accepté, refusé (« Erreur de paiement »), annulé ou expiré (« Annulé », stock restitué), remboursé.
- Remboursements depuis PrestaShop : un avoir créé sur la commande déclenche le remboursement chez Virevo.
- Si le service est momentanément injoignable, la commande est annulée proprement et le client retrouve son panier.
- Mode test (bac à sable) pour tout vérifier avant de passer en réel.
- Le moyen de paiement reste masqué tant que la configuration est incomplète : vos clients ne tombent jamais sur un paiement impossible.

**Prérequis**
- Un compte Virevo (inscription sur virevo.fr) et une boutique en euros.
- Les clés d'API et le secret de webhook se trouvent dans Virevo › Développeurs.

**Installation**
1. Modules › Gestionnaire de modules › Importer un module, puis choisissez le ZIP.
2. Cliquez sur Configurer et collez votre clé d'API test et le secret de webhook.
3. Copiez l'URL de webhook affichée sous le champ et enregistrez-la dans Virevo › Développeurs.
4. Passez une commande de test, puis passez en mode live avec votre clé live.

**Données transmises**
Seuls le montant, la devise, le numéro de commande et les adresses de retour de la boutique sont envoyés à Virevo. Aucune donnée personnelle du client ne transite par le module.

---

## English

### Name
Virevo: instant bank transfer payments

### Tagline
Customers pay by instant bank transfer from their own bank. No card fees, and the money lands directly on your IBAN.

### Description

**Get paid without card fees.**
Virevo adds a "Pay by instant bank transfer" option to your checkout. The customer picks their bank, approves the transfer in their banking app, and the order moves to "Payment accepted" on its own.

**Why merchants use it**
- No card fees, no terminal, no subscription.
- Money goes straight from the customer's account to your IBAN: Virevo never holds your funds.
- SEPA Instant Credit Transfer: irrevocable, with no card-style chargebacks.
- Available from €1 including tax.

**What the module does**
- Creates the order as "Awaiting bank wire payment", then redirects the customer to Virevo's secure payment page.
- Confirms the order through a signed webhook (HMAC-SHA256, replay protection).
- Handles all four outcomes: paid, declined ("Payment error"), cancelled or expired ("Cancelled", stock released), refunded.
- Refunds from PrestaShop: a credit slip on the order triggers the refund at Virevo.
- If the service is temporarily unreachable, the order is cancelled cleanly and the customer gets their cart back.
- Test mode (sandbox) to check everything before going live.
- The payment option stays hidden until the configuration is complete, so customers never meet a payment that cannot succeed.

**Requirements**
- A Virevo account (sign up at virevo.fr) and a store selling in euros.
- API keys and the webhook secret are in Virevo › Developers.
- The module interface is in French.

**Installation**
1. Modules › Module Manager › Upload a module, then pick the ZIP.
2. Click Configure and paste your test API key and webhook secret.
3. Copy the webhook URL shown under the field and register it in Virevo › Developers.
4. Place a test order, then switch to live mode with your live key.

**Data sent**
Only the amount, currency, order number and the store's return URLs are sent to Virevo. No personal customer data goes through the module.
