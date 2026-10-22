# Tester le module sur un PrestaShop local

1. `docker compose -p ps up -d` (PrestaShop 9 + MariaDB, port 8089). Le volume
   `psdata` est nécessaire : sans lui, l'installateur ne peut pas renommer le
   dossier d'administration et s'arrête.
2. `node mock-api.mjs` : faux `/v1/payments` sur le port 4010 (`FAIL=1` pour
   simuler une API en erreur).
3. Copier `virevopay/` dans `modules/` du conteneur, en remplaçant l'URL par
   défaut de `VirevoApiClient` par `http://host.docker.internal:4010` (dans le
   conteneur seulement), puis `php bin/console prestashop:module install virevopay`.
4. Poser `vrv_test_fake` et `whsec_test123` dans la configuration du module.
5. `node parcours.mjs <chemin playwright> <dossier de sortie> <dossier admin>` :
   commande invitée, webhook signé, captures de l'administration.
