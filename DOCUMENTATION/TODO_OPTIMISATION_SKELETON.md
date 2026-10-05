# TODO — Optimisation de `php-skeleton`

**Date :** 5 octobre 2026  
**Source :** `DOCUMENTATION/PLAN_OPTIMISATION_LIBS.md`  
**Nature :** feuille de route opérationnelle, mise à jour au fil de l’implémentation

## Périmètre

Cette checklist couvre l’optimisation de `php-skeleton`, de son installateur, de ses dépendances, de son bootstrap, de ses profils générés, de Docker, des tests et de la documentation.

**Exclusion ferme :** le moteur de templates alternatif et toutes les tâches qui lui sont associées sont hors périmètre.

## Légende

- `[ ]` à faire
- `[~]` en cours
- `[x]` terminé
- `P0` bloquant ou sécurité/fiabilité critique
- `P1` important pour l’architecture et la maintenabilité
- `P2` amélioration après stabilisation

## État d’avancement — 5 octobre 2026

### Validé pendant cette itération

- [x] `composer validate` passe sur le skeleton.
- [x] `composer audit` ne signale plus de vulnérabilité après mise à jour de PHPUnit vers `11.5.57`.
- [x] La suite PHPUnit passe : `38 tests`, `78 assertions`.
- [x] Les dépréciations dues à `ReflectionMethod::setAccessible()` ont été supprimées des tests.
- [x] Le profil base génère un projet local avec `.env`, sans configuration DB inutile.
- [x] Le projet local généré répond en HTTP `200` sur la route d’accueil.
- [x] Le profil Auth généré répond en HTTP `200` avec Doctrine/Auth installés.
- [x] Le profil Auth génère l’entité utilisateur et les migrations nécessaires.
- [x] Le mapping et la persistance Doctrine du profil Auth passent avec PDO SQLite en mémoire.
- [x] Le flux `AuthManager` SQLite couvre tentative de connexion, session active et déconnexion.
- [x] Le profil Auth génère un contrôleur `/login`, `/logout` et une route `/account` protégée.
- [x] La stack Docker temporaire MariaDB 11.3 est saine ; migrations, Doctrine et Auth passent sur MySQL/MariaDB.
- [x] Le profil Docker complet démarre Apache/PHP et valide `/health`, `/login`, CSRF, session Auth et `/account` par HTTP.
- [x] Le profil Docker de base ne génère plus de service MariaDB, de `depends_on` DB ni de variables DB inutiles.
- [x] Authentification et Doctrine sont couplés dans le profil généré.
- [x] Les contraintes générées Core/Router/Doctrine/Auth sont alignées sur les versions stables utilisées.
- [x] Les commandes Composer de l’installateur utilisent des arguments structurés via `proc_open`.
- [x] Le nettoyage Docker ne supprime plus la licence, la documentation, le lockfile ou le `composer.json` racine.
- [x] Xdebug, NVM et Node ne sont plus installés par défaut dans l’image Docker générée.
- [x] Le cache de vues est préparé via l’API existante de `core-php` et activé hors debug.
- [x] Le smoke test Auth généré ne révèle pas d’erreur fonctionnelle ; les appels `ReflectionProperty::setAccessible()` de `doctrine-php` ont été supprimés pour PHP 8.5.

### Restant prioritaire

- [x] Supprimer la configuration de rapport de couverture passive qui déclenchait un warning sans driver ; activer la couverture explicitement en CI avec PCOV/Xdebug.
- [x] Valider le profil Doctrine avec MariaDB dans Docker.
- [x] Valider le profil Auth avec flux HTTP de connexion réel derrière Apache.
- [x] Corriger les dépréciations PHP 8.5 dans `doctrine-php`/`core-php` ou attendre des versions amont corrigées, sans les masquer dans le skeleton.
- [x] Finaliser la séparation Docker développement/production.
- [x] Ajouter les tests de génération API et interface optionnelle.
- [ ] Découper progressivement `Installer` sans casser la compatibilité Composer.

---

## 0. Préparer la référence et les critères de réussite

### P0 — État initial reproductible

- [ ] Vérifier que seuls les fichiers de documentation attendus sont modifiés avant de commencer.
- [ ] Conserver une copie de référence de l’état actuel de `composer.json`, `composer.lock`, des templates et de l’installateur.
- [x] Exécuter `composer validate` sur le projet source.
- [x] Exécuter `composer audit` et enregistrer les vulnérabilités de référence.
- [x] Exécuter la suite PHPUnit actuelle et noter les échecs, dépréciations et métriques d’assertions.
- [ ] Mesurer le temps d’installation d’un projet minimal dans un répertoire temporaire.
- [x] Mesurer le temps de démarrage d’une application générée minimale.
- [ ] Mesurer la taille de l’image Docker de développement actuelle.
- [ ] Lister les versions réellement présentes des bibliothèques locales avant toute mise à niveau.

### P0 — Contrat de support

- [ ] Décider et documenter la version PHP minimale supportée.
- [ ] Confirmer si PHP `^8.1` reste la cible de base.
- [ ] Documenter séparément les profils qui imposent PHP `^8.2`.
- [ ] Définir les environnements officiellement supportés : local, CI, Docker développement, Docker production.
- [ ] Définir les profils générables officiellement supportés : base, sécurisé, base de données, authentification, API et interface optionnelle.
- [ ] Établir une matrice profil × version PHP × système de base de données.

### P0 — Critères globaux d’acceptation

- [x] Un projet de base démarre sans Doctrine, authentification, API ou bibliothèque d’interface optionnelle.
- [x] Le profil base de données ajoute Doctrine de façon cohérente et reproductible avec le smoke test SQLite.
- [x] Le profil authentification ajoute automatiquement les prérequis base de données.
- [x] Aucun paquet généré n’utilise une contrainte wildcard non justifiée.
- [x] Une installation locale génère un environnement fonctionnel sans correction manuelle obligatoire.
- [x] `composer validate` passe sur le skeleton et sur le projet de base généré.
- [x] `composer audit` ne signale aucune vulnérabilité acceptée sur le skeleton.
- [x] L’installateur refuse de relancer une génération sur un `composer.json` déjà applicatif.
- [ ] Les tests couvrent la génération et le démarrage de chaque profil supporté.

---

## 1. Stabiliser la politique de dépendances

### P0 — Dépendances directes du skeleton

- [ ] Réduire le profil de base aux dépendances réellement nécessaires au démarrage.
- [x] Utiliser `core-php` avec une contrainte de branche stable compatible avec la version PHP retenue.
- [x] Utiliser `php-router` avec une contrainte de branche stable compatible avec `core-php`.
- [x] Ne pas déclarer directement `php-dotenv` dans le profil de base si elle reste transitive via `core-php`.
- [x] Ne pas déclarer directement `php-cache` dans le profil de base si elle reste transitive via `core-php`.
- [ ] Vérifier si le validateur du bootstrap doit utiliser l’API déjà exposée par `core-php` plutôt qu’un binding redondant.
- [ ] Supprimer toute dépendance directe qui n’est pas utilisée par le code généré ou documenter précisément sa raison.
- [x] Définir `minimum-stability: stable` et vérifier l’absence de configuration qui autorise implicitement des versions instables.

### P0 — Versions et verrouillage

- [ ] Remplacer les contraintes trop larges par des contraintes de versions compatibles avec les bibliothèques locales.
- [x] Fixer la version de PHPUnit sur une branche corrigée et compatible avec la version PHP supportée.
- [x] Régénérer `composer.lock` après nettoyage des contraintes.
- [ ] Vérifier que le lockfile correspond bien au `composer.json` source.
- [ ] Vérifier que chaque projet généré obtient un lockfile cohérent.
- [ ] Refuser la génération si l’installation des dépendances échoue.
- [ ] Ajouter une vérification CI contre les retours à des contraintes `*`.
- [ ] Ajouter une vérification CI contre les versions majeures incompatibles entre bibliothèques internes.

### P1 — Profils de dépendances

- [x] Définir un profil de base ne contenant que le cœur et le routeur.
- [x] Définir un profil sécurisé ajoutant uniquement les middlewares et composants nécessaires.
- [x] Définir un profil base de données avec `doctrine-php` et `ext-pdo`.
- [x] Définir un profil authentification qui implique explicitement le profil base de données.
- [x] Définir un profil API avec `php-api` uniquement lorsque l’utilisateur le demande.
- [x] Définir un profil interface optionnelle séparé du profil de base.
- [x] Documenter les dépendances ajoutées par chaque profil et leur justification.
- [x] Vérifier que le choix d’un profil ne génère pas de dépendances inutilisées dans les autres profils.

### P1 — Bibliothèque API

- [x] Corriger dans `php-api` les contraintes wildcard de `core-php` et `doctrine-php`.
- [x] Aligner la contrainte PHP de `php-api` sur la version officiellement supportée.
- [x] Vérifier la compatibilité réelle de `php-api` avec les versions verrouillées du skeleton.
- [x] Régénérer et tester le lockfile de `php-api` avant de l’utiliser dans un profil généré.
- [x] Ajouter un test d’intégration du profil API avec le skeleton.

### P1 — Bibliothèque d’interface optionnelle

- [x] Conserver la bibliothèque d’interface hors du profil de base.
- [x] Documenter clairement son minimum PHP spécifique.
- [x] Vérifier qu’elle ne force pas des composants lourds dans les projets qui ne l’utilisent pas.
- [x] Ajouter un test de génération séparé pour ce profil.

### P2 — Générateur Docker

- [ ] Ne pas ajouter le générateur Docker comme dépendance applicative du projet généré.
- [ ] Décider si ses recettes doivent être consommées comme templates, comme outil séparé ou comme composant interne.
- [ ] Éviter de dupliquer la logique de génération Docker entre l’installateur et le générateur dédié.
- [ ] Documenter une seule source de vérité pour les fichiers Docker générés.

---

## 2. Corriger le contrat entre Core et Router

### P0 — Middlewares

- [x] Cartographier les interfaces et contrats de middleware de `core-php` et `php-router`.
- [x] Aligner l’interface middleware Core sur le contrat attendu par le routeur.
- [x] Vérifier que `CsrfMiddleware` peut être enregistré directement dans le pipeline du routeur.
- [x] Définir un contrat commun pour les middlewares de sécurité, de limitation de débit et de compression.
- [x] Éviter de maintenir un adaptateur spécifique au skeleton pour masquer une incompatibilité entre bibliothèques.
- [x] Ajouter des tests d’enregistrement et d’exécution du pipeline middleware.

### P0 — Réponse et post-traitement

- [x] Définir comment les middlewares qui modifient la réponse après l’exécution du contrôleur sont exécutés.
- [x] Intégrer proprement le traitement des en-têtes de sécurité.
- [x] Intégrer proprement la compression uniquement si la réponse complète est disponible au bon moment.
- [x] Vérifier que la compression ne s’applique pas aux réponses déjà compressées, aux flux ou aux réponses incompatibles.
- [x] Ajouter des tests sur les codes HTTP, les en-têtes et le corps de réponse.

### P1 — Ordre des middlewares

- [ ] Définir l’ordre officiel des middlewares du profil base.
- [ ] Définir l’ordre officiel du profil sécurisé.
- [ ] Définir l’ordre officiel du profil API.
- [ ] Définir les middlewares qui s’appliquent globalement et ceux qui s’appliquent par groupe de routes.
- [x] Documenter explicitement l’absence de CSRF par défaut sur les routes API stateless.
- [x] Vérifier que la limitation de débit ne bloque pas les routes de santé ou les migrations opérationnelles.

---

## 3. Repenser l’architecture de l’installateur

### P0 — Sécurité des fichiers

- [ ] Refuser par défaut l’installation dans un répertoire non vide.
- [ ] Ajouter une option explicite et documentée pour reprendre un répertoire existant si ce cas est réellement nécessaire.
- [x] Supprimer toute logique de nettoyage récursif non limitée aux fichiers créés par l’installateur.
- [x] Ne jamais supprimer le README, la licence, la documentation, le lockfile ou des fichiers inconnus de l’installateur.
- [ ] Écrire les fichiers dans une zone temporaire avant de les déplacer vers la destination finale.
- [ ] Prévoir une stratégie de rollback si une étape échoue après création partielle.
- [ ] Rendre l’installation idempotente ou échouer proprement avec un diagnostic explicite.

### P0 — Exécution des commandes

- [x] Remplacer le parsing fragile des chaînes de commandes par des arguments structurés pour les commandes Composer utilisées par l’installateur.
- [ ] Valider les binaires nécessaires avant de commencer la génération.
- [ ] Échapper correctement les chemins et arguments transmis aux processus externes.
- [ ] Capturer séparément sortie standard, sortie d’erreur et code retour.
- [ ] Arrêter immédiatement la génération lorsqu’une commande critique échoue.
- [ ] Ne jamais afficher les secrets présents dans les arguments ou l’environnement.
- [ ] Ajouter un mode verbeux contrôlé par l’utilisateur.
- [ ] Ajouter un mode non interactif adapté à la CI.

### P1 — Découpage de l’installateur

- [ ] Découper la classe monolithique en services ciblés : interaction, fichiers, templates, dépendances, Docker et validation.
- [ ] Isoler la résolution des options de profil de la génération des fichiers.
- [ ] Isoler la génération de `composer.json` de l’exécution de Composer.
- [ ] Isoler la génération de l’environnement de la génération du code applicatif.
- [ ] Réduire les méthodes statiques et injecter les services nécessaires.
- [ ] Définir des objets de configuration typés pour les choix de l’utilisateur.
- [ ] Centraliser les chemins générés afin d’éviter les divergences entre étapes.

### P1 — Templates générés

- [ ] Sortir les gros heredocs de l’installateur vers des templates versionnés.
- [ ] Organiser les templates par profil et par environnement.
- [ ] Éviter de dupliquer le bootstrap entre profils lorsque seule la configuration change.
- [ ] Ajouter une validation syntaxique de chaque template PHP généré.
- [ ] Ajouter une validation des placeholders non résolus.
- [ ] Documenter les variables disponibles dans chaque template.

### P1 — Entrées utilisateur

- [ ] Valider les noms de projet, namespaces, ports, hôtes, noms de bases et identifiants.
- [ ] Refuser les valeurs contenant des séparateurs de chemin ou des caractères de contrôle.
- [ ] Définir des valeurs par défaut sûres et cohérentes entre CLI, Docker et `.env`.
- [ ] Vérifier les collisions de ports avant de générer les fichiers Docker.
- [ ] Vérifier que les options incompatibles ne peuvent pas être sélectionnées ensemble.
- [ ] Produire un résumé final des choix sans afficher de secret.

---

## 4. Fiabiliser le bootstrap et la configuration générée

### P0 — Démarrage local

- [x] Générer systématiquement un `.env.example` complet pour le profil local.
- [x] Générer un `.env` local uniquement selon une règle documentée et sûre.
- [x] Vérifier que le bootstrap du profil base peut démarrer avec les fichiers effectivement générés.
- [ ] Corriger la divergence entre l’environnement local et l’environnement Docker.
- [x] Rendre la configuration Docker conditionnelle au profil sélectionné.
- [x] Autoriser les hôtes locaux valides de base de données lorsque l’installation utilise la machine hôte.
- [ ] Vérifier les extensions PHP indispensables avant le démarrage.
- [x] Produire une erreur lisible lorsque l’environnement est incomplet.

### P0 — Secrets et valeurs par défaut

- [x] Supprimer les mots de passe et secrets par défaut prévisibles.
- [x] Générer des secrets locaux aléatoires lorsque cela est nécessaire.
- [ ] Désactiver le mode debug dans les valeurs de production par défaut.
- [x] Ne pas exposer la base de données sur toutes les interfaces par défaut.
- [ ] Ne pas utiliser un hôte MySQL universel permissif dans les templates de production.
- [ ] Vérifier que les secrets ne sont pas écrits dans les logs, exceptions ou messages de succès.
- [ ] Documenter clairement quels fichiers doivent rester hors Git.

### P0 — Validation de configuration

- [ ] Centraliser les noms de variables d’environnement.
- [ ] Valider les types, valeurs obligatoires, ports et URLs.
- [ ] Refuser les valeurs invalides plutôt que de les convertir silencieusement.
- [ ] Distinguer les erreurs de configuration locales des erreurs de configuration production.
- [x] Vérifier qu’une configuration de base de données complète est présente avant d’activer Doctrine.
- [x] Vérifier qu’un secret d’authentification est présent avant d’activer l’authentification.

### P1 — Application et conteneur

- [ ] Construire le bootstrap autour des API officielles de `core-php`.
- [ ] Configurer l’application, le routeur et le conteneur avec une seule source de vérité.
- [ ] Enregistrer le logger et les services partagés avant l’exécution des routes.
- [ ] Éviter les singletons applicatifs dupliqués lorsqu’un service peut être injecté.
- [ ] Enregistrer un unique EntityManager par application lorsque Doctrine est activé.
- [ ] Vérifier que le bootstrap de base ne charge pas les services optionnels inutilisés.

---

## 5. Implémenter les profils fonctionnels

### P0 — Profil base

- [x] Générer uniquement le cœur, le routeur, la configuration minimale et un contrôleur d’exemple.
- [x] Générer une route de santé ou de vérification de démarrage clairement identifiée.
- [x] Vérifier le rendu PHP standard avec les mécanismes déjà présents dans le skeleton.
- [x] Vérifier qu’aucune configuration Doctrine ou authentification n’est chargée par défaut.
- [x] Vérifier le démarrage avec le minimum de dépendances.

### P1 — Profil sécurisé

- [x] Ajouter les en-têtes de sécurité avec le contrat middleware commun.
- [x] Activer CSRF uniquement sur les formulaires et routes qui en ont besoin.
- [x] Ajouter la limitation de débit avec une stratégie de stockage documentée.
- [x] Définir les valeurs de production et de développement séparément.
- [x] Ajouter des tests de présence et de valeur des en-têtes de sécurité.
- [x] Ajouter des tests de rejet CSRF et de limitation de débit.

### P0 — Profil base de données

- [x] Ajouter `doctrine-php` et vérifier `ext-pdo`.
- [x] Générer la configuration de connexion sans secret en dur.
- [x] Générer l’enregistrement unique de l’EntityManager.
- [x] Générer les répertoires et conventions d’entités, repositories et migrations.
- [x] Utiliser la commande officielle de migration fournie par la bibliothèque.
- [x] Ajouter un contrôle de connexion explicite et exploitable.
- [x] Ajouter un smoke test de migration sur une MariaDB temporaire dans Docker.
- [x] Tester les erreurs de connexion et de configuration.

### P0 — Profil authentification

- [x] Faire dépendre explicitement l’authentification du profil base de données.
- [x] Générer l’entité utilisateur conforme aux interfaces attendues.
- [x] Générer la migration utilisateur et la table des tokens persistants si nécessaire.
- [x] Enregistrer le gestionnaire d’authentification une seule fois.
- [x] Ajouter le middleware authentifié sur la route d’exemple protégée.
- [x] Générer des routes minimales de connexion et déconnexion compatibles CSRF.
- [ ] Ajouter les middlewares invité, rôle et permission uniquement lorsque requis.
- [x] Protéger une route d’exemple par middleware de groupe de route.
- [ ] Tester inscription, connexion, déconnexion, session expirée et accès interdit.
- [x] Tester le contrôleur Auth généré : formulaire, identifiants invalides, déconnexion et accès invité.
- [ ] Vérifier le stockage sécurisé des mots de passe et tokens.

### P1 — Profil API

- [x] Ajouter `php-api` uniquement sur demande explicite.
- [x] Générer une configuration API minimale et documentée.
- [x] Définir la gestion des erreurs et le format des réponses.
- [ ] Définir l’authentification API séparément de la session web si nécessaire.
- [x] Configurer CORS avec une liste d’origines explicite.
- [x] Appliquer la validation et la limitation de débit au niveau des routes API.
- [x] Ne pas activer CSRF par défaut sur les routes stateless.
- [x] Ajouter des tests de payload invalide et de réponse JSON.
- [ ] Ajouter un test d’erreur d’authentification API.

### P1 — Profil interface optionnelle

- [x] Générer ce profil uniquement sur demande.
- [x] Isoler ses dépendances et ses templates du profil base.
- [x] Vérifier sa compatibilité avec la version PHP annoncée.
- [x] Ajouter un smoke test de génération et de rendu.

---

## 6. Utiliser correctement les bibliothèques déjà en place

### P0 — Validation et requêtes

- [ ] Identifier chaque service actuellement réimplémenté dans le skeleton.
- [ ] Remplacer les wrappers redondants par les API publiques des bibliothèques existantes lorsque le contrat est stable.
- [ ] Éviter de mélanger deux validateurs pour une même responsabilité.
- [ ] Documenter les exceptions où un wrapper local est réellement nécessaire.
- [ ] Ajouter des tests contractuels pour les services consommés par les templates générés.

### P1 — Cache de vues

- [x] Utiliser les mécanismes de cache de vues déjà exposés par `core-php`.
- [ ] Activer le cache en production avec un répertoire configurable.
- [ ] Désactiver ou limiter le cache en développement selon le mode choisi.
- [ ] Vérifier les permissions et la création du répertoire de cache.
- [ ] Ajouter une commande ou procédure documentée pour vider le cache.
- [ ] Tester le rendu après invalidation et après changement de template.

### P1 — Cache applicatif

- [ ] Utiliser `php-cache` uniquement pour les besoins métier nécessitant un cache applicatif.
- [ ] Injecter le service de cache dans les services qui l’utilisent.
- [ ] Utiliser un driver mémoire pour les tests.
- [ ] Utiliser un driver fichier en développement si nécessaire.
- [ ] Utiliser Redis en production uniquement si l’infrastructure le fournit réellement.
- [ ] Définir TTL, invalidation, sérialisation et comportement en cas d’indisponibilité.
- [ ] Ne pas créer un nouveau service de cache local qui duplique l’abstraction existante.
- [ ] Ajouter des tests de hit, miss, expiration et fallback.

### P1 — Doctrine

- [ ] Utiliser les conventions et commandes Doctrine déjà fournies.
- [ ] Éviter de créer une seconde couche ORM dans le skeleton.
- [ ] Vérifier les transactions sur les opérations d’écriture.
- [ ] Repérer et tester les risques de requêtes N+1 sur les exemples générés.
- [ ] Ajouter les index nécessaires aux tables générées.
- [ ] Documenter le cycle migration, rollback et déploiement.

---

## 7. Optimiser Docker et l’environnement d’exécution

### P0 — Séparation développement / production

- [ ] Séparer clairement les images et configurations de développement et de production.
- [ ] Retirer Xdebug de l’image de production.
- [ ] Retirer NVM, Node et outils de confort de l’image de production lorsqu’ils ne sont pas nécessaires à l’exécution.
- [ ] Installer uniquement les extensions PHP requises par le profil choisi.
- [ ] Activer et configurer OPcache en production.
- [ ] Épingler les versions des images de base et des outils essentiels.
- [ ] Vérifier la reproductibilité d’un build sans accès implicite à l’environnement local.

### P1 — Sécurité et exploitation

- [ ] Ne pas exposer le port de base de données par défaut en production.
- [x] Ajouter des healthchecks utiles pour l’application et MariaDB (`/health` côté HTTP, `healthcheck.sh` côté DB).
- [ ] Exécuter le processus applicatif avec un utilisateur non privilégié lorsque la compatibilité est validée.
- [ ] Vérifier les permissions des volumes de cache, logs et uploads.
- [ ] Limiter les services exposés dans le réseau Docker.
- [ ] Documenter les variables et secrets nécessaires au déploiement.

### P1 — Uploads et fichiers publics

- [ ] Définir un répertoire d’upload séparé du code exécutable.
- [ ] Refuser les extensions dangereuses et les doubles extensions.
- [ ] Vérifier le type réel du fichier et sa taille.
- [ ] Générer des noms de fichiers non prédictibles.
- [ ] Empêcher l’exécution de scripts dans le répertoire d’upload.
- [ ] Ajouter des tests de traversée de chemin et de fichier malveillant.

### P2 — Performance

- [ ] Mesurer le gain OPcache après activation.
- [ ] Mesurer l’impact du cache de vues.
- [ ] Comparer la taille et le temps de build des images avant/après.
- [ ] Vérifier que les optimisations ne dégradent pas les logs, le diagnostic ou le démarrage local.

---

## 8. Renforcer tests, qualité et CI

### P0 — Tests unitaires

- [x] Corriger les dépréciations PHPUnit existantes et supprimer les appels Reflection obsolètes des tests.
- [ ] Augmenter la couverture des cas d’échec de l’installateur.
- [ ] Tester la validation des entrées utilisateur.
- [ ] Tester la sérialisation des fichiers `.env`.
- [x] Tester les valeurs par défaut sûres.
- [x] Tester le registre des dépendances par profil.
- [x] Tester l’ordre et le contrat des middlewares.

### P0 — Tests de génération

- [x] Générer un projet base dans un répertoire temporaire.
- [x] Générer un projet sécurisé dans un répertoire temporaire.
- [x] Générer un projet base de données dans un répertoire temporaire.
- [x] Générer un projet authentifié dans un répertoire temporaire.
- [x] Générer un projet API dans un répertoire temporaire.
- [x] Générer un projet avec l’interface optionnelle dans un répertoire temporaire.
- [x] Vérifier les fichiers attendus pour chaque profil.
- [x] Vérifier l’absence des fichiers et dépendances non sélectionnés.
- [ ] Vérifier que le projet généré ne référence aucun placeholder non résolu.

### P0 — Smoke tests générés

- [x] Exécuter `composer validate` dans chaque projet généré.
- [ ] Exécuter les tests du projet généré.
- [ ] Démarrer l’application générée.
- [ ] Appeler la route de santé ou la route d’accueil.
- [ ] Vérifier un rendu de réponse correct.
- [x] Vérifier les erreurs de configuration avec un `.env` incomplet.
- [x] Vérifier la génération et l’exécution des migrations lorsque Doctrine est activé sur MariaDB.
- [x] Vérifier le flux `AuthManager` généré sur SQLite : utilisateur, hash, session et logout.

### P1 — Sécurité et régression

- [x] Exécuter `composer audit` sur le skeleton et sur les projets générés.
- [x] Ajouter un test qui détecte les secrets en clair dans les fichiers générés.
- [ ] Ajouter un test qui détecte les commandes destructives sur le répertoire cible.
- [ ] Ajouter un test de relance de l’installateur.
- [ ] Ajouter un test de projet cible non vide.
- [ ] Ajouter un test d’échec de Composer et de rollback.
- [ ] Ajouter un test de chemins contenant des espaces et caractères spéciaux.

### P1 — Qualité de code

- [ ] Ajouter un lint PHP sur le code source et les templates.
- [ ] Ajouter un analyseur statique avec un niveau de départ documenté.
- [ ] Ajouter un outil de formatage ou de vérification de style.
- [ ] Vérifier les types de retour et paramètres publics de l’installateur.
- [ ] Réduire progressivement la taille et la complexité des classes principales.
- [ ] Publier les métriques de tests, dépréciations et durée d’installation dans la CI.

### P2 — Matrice CI

- [ ] Tester au minimum la version PHP minimale et la version PHP courante supportée.
- [ ] Tester les profils base, sécurisé, base de données, authentification et API.
- [ ] Tester les scénarios sans extensions optionnelles.
- [ ] Tester les scénarios Docker lorsque Docker est disponible dans la CI.
- [ ] Tester l’installation avec et sans mode interactif.

---

## 9. Observabilité et données sensibles

### P0 — Logs

- [ ] Ne jamais logger les mots de passe, tokens, clés ou chaînes de connexion complètes.
- [ ] Réduire ou anonymiser les données personnelles et adresses IP dans les logs.
- [ ] Éviter de logger les requêtes complètes contenant des paramètres sensibles.
- [ ] Ajouter des identifiants de corrélation lorsque cela aide au diagnostic.
- [ ] Définir les niveaux de logs par environnement.
- [ ] Vérifier la rotation et la taille maximale des fichiers de logs.

### P1 — Erreurs

- [ ] Afficher des erreurs détaillées uniquement en développement.
- [ ] Retourner des erreurs génériques en production.
- [ ] Ne pas exposer les chemins absolus, versions ou secrets dans les réponses publiques.
- [ ] Standardiser les erreurs HTTP du skeleton et des projets générés.
- [ ] Tester les erreurs 400, 401, 403, 404, 422, 429 et 500 lorsque le profil les utilise.

---

## 10. Nettoyer et aligner la documentation

### P0 — Documentation fiable

- [ ] Réécrire le README à partir des comportements réellement générés.
- [ ] Supprimer les promesses de fonctionnalités non présentes.
- [ ] Corriger les exemples de commandes et de chemins.
- [ ] Documenter les prérequis PHP, Composer, extensions et Docker.
- [ ] Documenter les profils et leurs dépendances.
- [ ] Documenter les différences entre développement et production.
- [ ] Documenter les limites connues et les décisions de compatibilité.

### P1 — Guide d’installation

- [ ] Ajouter un parcours minimal de génération.
- [ ] Ajouter un parcours base de données.
- [ ] Ajouter un parcours authentification.
- [ ] Ajouter un parcours API.
- [ ] Ajouter les commandes de validation post-génération.
- [ ] Ajouter une procédure de diagnostic lorsque le démarrage échoue.
- [ ] Ajouter une procédure de mise à niveau des dépendances.

### P1 — Maintenance

- [ ] Documenter la politique de versions des bibliothèques internes.
- [ ] Documenter la procédure de mise à jour du lockfile.
- [ ] Documenter la procédure d’audit de sécurité.
- [ ] Documenter la procédure de publication d’une nouvelle version du skeleton.
- [ ] Ajouter un changelog qui distingue corrections, changements de dépendances et changements de génération.
- [ ] Vérifier que tous les liens et chemins de la documentation existent.

---

## 11. Ordre recommandé d’exécution

Les tâches doivent être traitées dans cet ordre pour éviter de stabiliser des templates sur des contrats encore incompatibles :

1. [ ] Établir la référence et la matrice de support — section 0.
2. [ ] Corriger les contraintes Composer, le lockfile et les vulnérabilités — section 1.
3. [ ] Aligner les contrats Core/Router et le pipeline de réponse — section 2.
4. [ ] Sécuriser puis découper l’installateur — section 3.
5. [ ] Corriger le bootstrap, l’environnement et les secrets — section 4.
6. [ ] Stabiliser le profil base — section 5.
7. [ ] Ajouter successivement les profils sécurisé, base de données et authentification — section 5.
8. [ ] Ajouter le profil API puis l’interface optionnelle comme profils isolés — section 5.
9. [ ] Remplacer les réimplémentations par les API des bibliothèques existantes — section 6.
10. [ ] Séparer et durcir les images Docker — section 7.
11. [ ] Ajouter les tests de génération, smoke tests et contrôles CI — section 8.
12. [ ] Finaliser logs, sécurité documentaire et guide de maintenance — sections 9 et 10.

## 12. Jalons de livraison

### Jalon A — Base saine

- [ ] Dépendances verrouillées et auditables.
- [ ] Installateur non destructif.
- [ ] Projet de base généré et démarrable.
- [ ] Bootstrap minimal couvert par un smoke test.

### Jalon B — Fondation technique

- [ ] Contrats middleware alignés.
- [ ] Configuration validée et secrets protégés.
- [ ] Cache de vues branché sur les API existantes.
- [ ] Docker développement et production séparés.

### Jalon C — Profils métier

- [ ] Profil base de données fonctionnel.
- [ ] Profil authentification fonctionnel.
- [ ] Profil API fonctionnel.
- [ ] Profil interface optionnelle fonctionnel et isolé.

### Jalon D — Industrialisation

- [ ] Tests de génération exécutés en CI.
- [ ] Matrice PHP/profils validée.
- [ ] Audit Composer automatisé.
- [ ] Documentation alignée avec les sorties réelles.

---

## 13. Gate de validation finale

- [ ] Aucun code généré ne dépend d’une bibliothèque absente du profil sélectionné.
- [ ] Aucun profil ne charge inutilement les services d’un autre profil.
- [ ] Aucun wildcard de dépendance ne subsiste sans justification documentée.
- [ ] Aucun secret prévisible n’est généré par défaut.
- [ ] Aucun nettoyage destructif ne peut viser le répertoire utilisateur sans confirmation explicite.
- [ ] Les middlewares enregistrés respectent un contrat unique et testé.
- [ ] Les migrations et commandes Doctrine fonctionnent dans un projet généré.
- [ ] Les routes protégées et les réponses d’erreur sont testées.
- [ ] Les images de production n’embarquent pas les outils de développement inutiles.
- [ ] Les tests de génération passent sur toutes les combinaisons officiellement supportées.
- [ ] `composer validate` et `composer audit` passent partout.
- [ ] Le README, le guide d’installation et le changelog sont cohérents avec le comportement livré.
- [ ] Une nouvelle installation peut être réalisée à partir du dépôt sans intervention manuelle non documentée.
