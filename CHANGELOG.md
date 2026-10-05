# Changelog

Tous les changements notables de ce projet seront documentés dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère à [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

### Profils et sécurité

- Ajout du profil sécurisé optionnel avec validation des requêtes, limitation de débit, en-têtes HTTP et compression gzip.
- HSTS est activé uniquement en production et les compteurs de limitation sont stockés dans `storage/cache/rate-limit`.
- Documentation et tests de génération du profil sécurisé ajoutés.
- Ajout d’un test d’intégration qui installe les dépendances du profil API et instancie ses contrôleur et entité générés.
- Le profil API génère une allowlist CORS vide par défaut et documente `API_CORS_ORIGINS`.
- Les actions CRUD API générées renvoient désormais les erreurs au format JSON Problem Details (RFC 7807).
- Le profil API cible validation et limitation de débit sur `/api` sans compter la route `/health`.
- Le bootstrap Doctrine vérifie explicitement la connexion et remonte une erreur de configuration contextualisée.
- Les tests du profil Doctrine couvrent les variables DB manquantes et la lecture des valeurs depuis `.env`.
- Ajout d’un smoke test Auth avec installation des dépendances, formulaire de connexion, identifiants invalides et déconnexion.
- Le smoke test Auth vérifie aussi le hash du mot de passe, la connexion valide, la session active et la réponse du compte protégé.
- Ajout d’un smoke test du profil de base : installation Composer, validation et démarrage réel de `/health`.
- Ajout d’un smoke test du profil sécurisé avec installation Composer et exécution réelle des middlewares sur `/health`.
- Ajout d’un smoke test du profil base de données avec installation Composer et EntityManager SQLite.
- Ajout de tests du validateur d’environnement pour les secrets aléatoires, secrets trop courts et locales invalides.
- Renforcement de la matrice de génération : profils Auth/DB/API/Vision validés par Composer et dépendances non sélectionnées vérifiées.
- Correction de la réponse `/account` pour les visiteurs non authentifiés.

## [1.5.13] - 2025-01-XX

### Corrections
- ✅ Correction de la régénération de l'autoloader
  - Retrait de `2>&1` de la commande dans `regenerateAutoloader()` car `safeExec()` l'ajoute déjà lors de l'exécution
  - La validation de sécurité ne rejette plus la commande de régénération de l'autoloader

## [1.5.12] - 2025-01-XX

### Corrections
- ✅ Correction de la validation des commandes `composer` dans `safeExec()`
  - La méthode extrait maintenant le basename de la commande pour accepter les chemins complets
  - Ajout de `composer.phar` à la whitelist des commandes autorisées
  - Les installations de packages et la régénération de l'autoloader fonctionnent maintenant correctement

## [1.5.11] - 2025-12-17

### Maintenance
- Synchronisation de `composer.lock` avec `composer.json` après installation des dépendances

## [1.5.10] - 2025-01-XX

### Qualité
- ✅ **PHASE 2.2**: Documentation technique complète
  - Création de `DOCUMENTATION/INSTALLATION.md` : Guide complet d'installation
  - Création de `DOCUMENTATION/SECURITY.md` : Mesures de sécurité et bonnes pratiques
  - Création de `DOCUMENTATION/DOCKER.md` : Configuration et utilisation de Docker
  - Total : 3 fichiers, ~600 lignes de documentation

### Corrections
- ✅ Exclusion des fichiers de tests du skeleton généré
  - Ajout de `tests`, `phpunit.xml`, `.phpunit.cache`, `coverage`, `CHANGELOG.md` à la liste des fichiers à supprimer
  - Suppression automatique du dossier `tests` et de `phpunit.xml` s'ils sont copiés par erreur dans `www/`

## [1.5.9] - 2025-01-XX

### Qualité
- ✅ **PHASE 2.1**: Création de tests unitaires et d'intégration
  - Ajout de PHPUnit 11.5 comme dépendance de développement
  - Création de `phpunit.xml` pour la configuration des tests
  - Création de `tests/SafeExecTest.php` (20 tests pour la sécurisation de `exec()`)
  - Création de `tests/InstallerSecurityTest.php` (5 tests pour les injections de commandes)
  - Création de `tests/InstallerHelperTest.php` (10 tests pour les méthodes utilitaires)
  - Total : 35 tests, 55 assertions
  - Correction de la logique de `safeExec()` pour autoriser `&&` dans le contexte de `cd`
  - Correction de `safeShellExec()` pour gérer correctement les redirections

### Modifications
- `src/Installer.php` : Amélioration de la validation dans `safeExec()` pour autoriser `&&` dans les commandes avec `cd`
- `src/Installer.php` : Correction de `isExecutable()` et `findComposer()` pour ne plus utiliser de redirections dans `safeShellExec()`
- `composer.json` : Ajout de `require-dev` pour PHPUnit et configuration de `autoload-dev`
- `.gitignore` : Ajout de `.phpunit.cache/` et `coverage/`

## [1.5.8] - 2025-01-XX

### Sécurité
- ✅ **PHASE 1.1**: Sécurisation de l'utilisation de `exec()` et `shell_exec()`
  - Ajout de la méthode `safeExec()` pour exécuter des commandes de manière sécurisée
  - Ajout de la méthode `safeShellExec()` pour exécuter des commandes shell_exec de manière sécurisée
  - Whitelist de commandes autorisées (`composer`, `which`)
  - Protection contre le path traversal (`..`)
  - Protection contre l'accès aux chemins système sensibles (`/etc`, `/bin`, etc.)
  - Protection contre les caractères dangereux (`;`, `&`, `|`, `` ` ``, `$`, `<`, `>`)
  - Remplacement de tous les appels directs à `exec()` et `shell_exec()` par les méthodes sécurisées
  - Gestion des exceptions avec messages d'erreur clairs

### Modifications
- `src/Installer.php` : Remplacement de 5 appels non sécurisés par des appels sécurisés
  - `installPackage()` : Utilise maintenant `safeExec()`
  - `installPackageInDocker()` : Utilise maintenant `safeExec()`
  - `regenerateAutoloader()` : Utilise maintenant `safeExec()`
  - `findComposer()` : Utilise maintenant `safeShellExec()`
  - `isExecutable()` : Utilise maintenant `safeShellExec()`
