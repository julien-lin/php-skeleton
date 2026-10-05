# Audit sévère — `php-skeleton`

**Date de l’audit :** 5 octobre 2026  
**Périmètre :** installateur Composer, génération locale et Docker, sécurité, performance, packaging, tests et documentation.  
**Contrainte :** audit uniquement ; aucun code applicatif n’a été modifié.

## Verdict exécutif

`php-skeleton` est exploitable comme prototype pédagogique, mais il ne mérite pas aujourd’hui les mentions « production-ready », « zero configuration » ou « performance optimized » présentes dans la documentation.

**Note globale : 2,5/10 — release bloquée pour un usage production.**

| Domaine | Note | Verdict |
|---|---:|---|
| Architecture | 2/10 | `Installer.php` est une God class de 2 113 lignes, presque entièrement statique. |
| Fiabilité de génération | 2/10 | Le parcours local démarre sans `.env` ; Auth sans Doctrine génère un bootstrap invalide. |
| Sécurité | 3/10 | Defaults dangereux, debug activé, image Docker de développement utilisée comme base générale. |
| Performance | 3/10 | Image lourde, Xdebug permanent, logs synchrones, aucun profil dev/prod. |
| Tests | 3/10 | 35 tests ciblés sur le shell, aucun test de génération end-to-end. |
| Documentation | 1/10 | Plusieurs documents décrivent des fichiers et fonctionnalités absents ou différents. |
| Distribution | 3/10 | Lock désynchronisé, version hardcodée, artefacts importants supprimés en mode Docker. |

## Preuves exécutées

Contrôles réalisés le 5 octobre 2026 :

- `php -l` sur le code PHP : **OK**.
- `vendor/bin/phpunit --testdox` : **35 tests, 55 assertions, OK**, mais **17 dépréciations** et un avertissement d’absence de driver de couverture.
- `composer validate --strict` : **échec de conformité** ; lock non synchronisé et champ `version` déconseillé.
- `composer audit --locked` : **1 vulnérabilité haute** dans `phpunit/phpunit` `11.5.46`, CVE-2026-24765 (désérialisation non sûre dans le traitement PHPT de la couverture).
- Parcours de génération locale dans un répertoire temporaire : l’installateur termine, puis `php public/index.php` échoue immédiatement car aucun `.env` local n’a été créé.
- Parcours Docker dans un répertoire temporaire : `docker compose config --quiet` passe, mais les défauts fonctionnels et de sécurité ci-dessous restent présents.

Les essais ont été exécutés dans des répertoires temporaires hors dépôt ; le dépôt audité ne contient que ce rapport comme changement non suivi.

## 1. Bloquants fonctionnels

### 1.1 Installation locale incomplète — critique

`postInstall()` appelle `configureEnv()` uniquement dans la branche Docker (`src/Installer.php:18-33`). Le parcours local appelle pourtant `createConfigDatabase()` (`src/Installer.php:1059-1089`), dont la configuration exige `MYSQL_DATABASE`, `MYSQL_USER` et `MYSQL_PASSWORD` (`src/Installer.php:907-916`).

Résultat observé dans une génération locale propre :

```text
Erreur lors du chargement du fichier .env: Le fichier .env n'existe pas
```

Le projet généré n’a pas de `.env` local exploitable ni de `.env.example` local adapté. La promesse « fonctionne immédiatement » est fausse.

**Correction prioritaire :** générer un environnement local séparé, avec `DB_HOST=127.0.0.1` par défaut et un `.env.example` cohérent, ou ne pas générer de configuration DB tant que l’utilisateur ne l’a pas demandée.

### 1.2 Auth sélectionnable sans Doctrine — critique

Le wizard permet `Auth = oui` et `Doctrine = non` (`src/Installer.php:18-21`). Dans ce cas, le bootstrap produit un `AuthManager` sans `EntityManager` (`src/Installer.php:1691-1700`). Or `auth-php` exige cet objet pour construire `DatabaseUserProvider` (`../auth-php/src/Auth/AuthManager.php:72-93`).

En plus, aucune classe `App\Entity\User` n’est générée. L’option est donc soit invalide, soit insuffisamment documentée.

**Correction prioritaire :** rendre Doctrine obligatoire quand Auth est choisi, ou générer explicitement un provider non-Doctrine et une entité utilisateur complète.

### 1.3 Configuration DB Docker imposée en local — critique

Le même fichier généré refuse `localhost` et `127.0.0.1` sans connaître le mode d’exécution (`src/Installer.php:921-927`). Cette règle est correcte dans le conteneur PHP, mais fausse pour une installation locale.

**Correction prioritaire :** générer deux configurations distinctes ou transmettre le contexte `docker/local` au générateur.

### 1.4 Installation non idempotente et destructive — critique

En Docker, `moveExistingFiles()` puis `cleanupRootFiles()` déplacent ou suppriment des éléments du projet (`src/Installer.php:424-476`) : `composer.json`, `composer.lock`, `LICENSE`, README, tests, `src`, `public`, `vendor`, etc.

Risques :

- perte d’attribution et de documentation ;
- impossibilité de relancer l’installateur proprement ;
- perte de fichiers utilisateur si le hook est relancé dans un répertoire non vierge ;
- état partiel difficile à reprendre après une erreur Composer ou filesystem.

**Correction prioritaire :** refuser les répertoires non vierges, écrire dans un nouveau répertoire de destination et ne jamais supprimer sans confirmation explicite.

## 2. Architecture et maintenabilité

### 2.1 God class monolithique — critique

`src/Installer.php` fait 2 113 lignes et concentre : CLI, validation d’entrées, filesystem, exécution shell, Composer, Docker, génération PHP/YAML/INI/Bash/HTML, permissions et nettoyage.

L’état global `self::$containerNames` (`src/Installer.php:12`) et les méthodes statiques rendent les tests et l’injection de dépendances artificiels. Toute modification d’un template risque de casser le wizard, la génération Docker et le parcours local simultanément.

**Refonte recommandée :** séparer au minimum :

1. `InstallerWizard` ;
2. `ProjectScaffold` ;
3. `TemplateRenderer` ;
4. `ComposerRunner` ;
5. `DockerScaffold` ;
6. `InputValidator`.

### 2.2 Templates embarqués en heredoc — critique

Les templates générés sont des chaînes de plusieurs centaines de lignes dans `Installer.php` : bootstrap, contrôleurs, vues, Dockerfile, Compose, INI, scripts et `.env`.

Conséquences :

- aucun lint indépendant des fichiers générés ;
- formatage et revue Git difficiles ;
- duplication entre les variantes locale et Docker ;
- dérive inévitable entre code, documentation et sortie réelle.

**Optimisation structurante :** déplacer les fichiers dans `templates/skeleton/`, les versionner comme des artefacts normaux et n’utiliser qu’un remplacement de variables strictement borné.

### 2.3 Racine détectée par `getcwd()` — élevé

`getProjectRoot()` retourne `getcwd()` (`src/Installer.php:381-384`). Le résultat dépend du répertoire depuis lequel Composer ou un script externe est lancé. C’est fragile en CI, avec un hook manuel ou depuis un sous-répertoire.

**Correction :** transmettre explicitement la destination au hook ou la résoudre depuis le contexte Composer, avec vérification que le répertoire cible est bien le projet attendu.

## 3. Sécurité

### 3.1 Secrets et debug dangereux par défaut — critique

Les valeurs suivantes sont émises par défaut (`src/Installer.php:1132-1141`, `1231-1240`, `1318-1354`) :

| Valeur | Risque |
|---|---|
| `MYSQL_ROOT_PASSWORD=root` | Mot de passe trivial et réutilisable. |
| `MYSQL_PASSWORD=app_password` | Secret applicatif trivial. |
| `MYSQL_ROOT_HOST=%` | Accès root autorisé depuis tous les hôtes du réseau concerné. |
| `APP_DEBUG=1` | Fuite de stack traces et d’informations internes. |
| `PHP_DISPLAY_ERRORS=On` | Fuite d’erreurs côté client. |
| `3306:3306` | Base exposée sur l’interface hôte sans nécessité. |

Le seul bon point est la génération cryptographique de `APP_SECRET` via `random_bytes(32)` (`src/Installer.php:1197`).

**Correction :** secrets aléatoires obligatoires, `APP_DEBUG=0` en profil production, `MYSQL_ROOT_HOST` limité, et port MariaDB non publié par défaut.

### 3.2 Écriture `.env` sans sérialisation — élevé

Les valeurs utilisateur sont concaténées directement (`src/Installer.php:1170-1207`) :

```php
$content .= "{$key}={$value}\n";
```

Un mot de passe ou un nom contenant espace, `#`, `=`, guillemets, retour à la ligne ou caractère de contrôle peut casser le parsing dotenv ou modifier la configuration. Les ports, noms de conteneurs et identifiants ne sont pas validés (`src/Installer.php:1154-1167`).

**Correction :** valider chaque champ par type et sérialiser selon la grammaire dotenv ; refuser les retours à la ligne et caractères de contrôle.

### 3.3 Exécution shell inutilement complexe — élevé

`safeExec()` et `safeShellExec()` vérifient une chaîne par `preg_split()`/`explode()` avant d’appeler quand même `exec()`/`shell_exec()` (`src/Installer.php:233-346`).

La whitelist est préférable à une exécution libre, mais le parsing d’un langage shell par expressions régulières reste fragile : quoting, espaces, chemins atypiques et règles de shell sont partiellement reproduits. Les appels Composer devraient recevoir un tableau d’arguments via `proc_open()` ou une abstraction de processus éprouvée.

**Correction :** supprimer `cd &&`, passer le `cwd` séparément et exécuter uniquement un binaire résolu avec une liste d’arguments contrôlés.

### 3.4 Image Docker de développement publiée comme base — critique

Le Dockerfile généré installe des outils inutiles au runtime et non suffisamment maîtrisés (`src/Installer.php:1388-1433`) :

- Xdebug installé et activé en permanence (`src/Installer.php:1416-1418`, `1443-1458`) ;
- Node installé via `curl | bash` avec NVM (`src/Installer.php:1420-1426`) ;
- `git`, `wget`, outils de compilation et bibliothèques de build conservés dans l’image ;
- aucun profil production distinct ;
- pas de pinning par digest pour les images Composer/PHP/MariaDB.

Cela augmente taille, temps de build, surface d’attaque et consommation CPU. Le skeleton ne configure pas non plus explicitement OPcache ou une stratégie de cache applicatif réellement générée.

**Correction :** images dev/prod distinctes, multi-stage, sans Xdebug ni Node en production, versions/digests maîtrisés et OPcache configuré dans le profil runtime.

### 3.5 Uploads publics non durcis — élevé

`public/uploads/` est créé (`src/Installer.php:546-577`) mais aucune règle n’interdit explicitement l’exécution de PHP dans ce répertoire. Le `.htaccess` généré protège certains fichiers sensibles, pas les extensions exécutables (`src/Installer.php:1466-1480`).

**Correction :** stocker les uploads hors document root quand possible ; sinon interdire PHP/CGI, contrôler MIME et extension, limiter la taille et renommer côté serveur.

### 3.6 Logs trop bavards et synchrones — moyen/élevé

Le listener généré enregistre la query string et l’adresse IP pour chaque requête (`src/Installer.php:700-730`). Cela peut exposer tokens, emails ou données personnelles, tout en ajoutant une écriture disque synchrone sur le chemin HTTP.

**Correction :** liste blanche des champs, masquage des secrets, identifiant de corrélation, rotation et niveau de log configurable ; désactiver les logs détaillés en production.

## 4. Performance

### 4.1 Coût Docker disproportionné — élevé

Pour un skeleton PHP minimal, Node/NVM, Xdebug, Git, Wget, GD, ICU et les dépendances de compilation sont installés par défaut. Le build est plus lent et l’image runtime inutilement grande.

**Optimisation :** ne conserver que les extensions réellement requises ; déplacer tooling et Xdebug dans une image dev ; utiliser une étape de build Composer séparée.

### 4.2 Bootstrap et I/O à chaque requête — moyen

Le bootstrap charge l’environnement, la configuration, initialise logger, événements, sessions et middlewares à chaque requête. Une partie est normale en PHP classique, mais les listeners ajoutent des écritures et la configuration d’erreur peut créer/vérifier le répertoire de logs à chaque démarrage.

**Optimisation :** réduire les listeners par défaut, utiliser un logger avec buffer/rotation, activer OPcache et distinguer clairement dev/prod.

### 4.3 Contradiction entre documentation de cache et sortie réelle — élevé

`OPTIMISATIONS.md` annonce `CacheService`, `ExampleController`, `UserRepository` et des migrations générées. Aucun de ces fichiers n’existe dans le dépôt ni dans les artefacts produits par l’installateur actuel. La documentation ne peut donc pas être utilisée pour guider une optimisation réelle.

## 5. Packaging et reproductibilité

### 5.1 `composer.lock` désynchronisé — élevé

`composer validate --strict` signale que le lock n’est pas à jour avec `composer.json`. Le repo source n’est donc pas reproductible au niveau attendu.

En mode local, `copyComposerJson()` écrase en plus le `composer.json` (`src/Installer.php:943-993`) sans régénérer proprement le lock. En mode Docker, `composer.lock` est supprimé par `cleanupRootFiles()`.

### 5.2 Contraintes générées trop larges — élevé

Le projet généré utilise `core-php: ^1.0` et `php-router: ^1.0` alors que le skeleton source déclare respectivement `^1.4` et `^1.2` (`src/Installer.php:948-953`). Le code généré peut donc être résolu avec des API anciennes incompatibles.

Le `composer.json` généré ne contient pas de licence (`src/Installer.php:967-977`) et son nom est artificiellement `your-vendor/...`, ce qui produit aussi un avertissement de validation.

### 5.3 Vulnérabilité de l’outillage de test — haute

Le lock installe `phpunit/phpunit 11.5.46`. `composer audit --locked` signale CVE-2026-24765, de sévérité haute. Même si PHPUnit est une dépendance de développement, le lock livré avec le skeleton doit être mis à jour avant distribution.

**Correction :** mettre à jour vers une version corrigée compatible avec la branche PHPUnit utilisée, puis faire échouer la CI sur `composer audit`.

## 6. Tests et qualité

### 6.1 Couverture fonctionnelle insuffisante — critique

Les tests se concentrent sur les helpers et le parsing de commandes (`tests/*.php`). Aucun test ne couvre :

- génération locale complète ;
- génération Docker complète ;
- contenu et validité du `composer.json` généré ;
- scénario Auth avec/sans Doctrine ;
- absence de `.env` ou de secrets exposés ;
- validation Compose ;
- lint des fichiers PHP effectivement générés ;
- non-régression des templates.

### 6.2 Faux sentiment de sécurité

Certains tests de sécurité acceptent le simple fait que la méthode retourne sans exception, avec `assertTrue(true)`, au lieu de vérifier le résultat et l’absence d’effets de bord. Les tests exécutent aussi réellement `composer --version` et `which`, ce qui les rend dépendants de l’environnement de la machine.

**Correction :** tests d’intégration dans des répertoires temporaires, doubles de processus, assertions sur tous les fichiers générés et tests négatifs qui prouvent qu’aucune commande ni écriture non autorisée n’a eu lieu.

### 6.3 Outillage absent

Le dépôt ne contient pas de CI, de PHPStan/Psalm, de PHP-CS-Fixer/PHPCS, ni de script Composer `test` stable. Les 17 dépréciations PHPUnit doivent être supprimées avant une montée de version PHP.

## 7. Documentation et cohérence produit

Les écarts sont trop nombreux pour faire confiance à la documentation actuelle :

| Document | Écart constaté |
|---|---|
| `README.md`, `README.fr.md` | Titre v1.5.8 alors que `composer.json` porte 1.5.13 ; claims production/cache excessifs ; typo `ccomposer`. |
| `DOCUMENTATION/INSTALLATION.md` | Référence `php vendor/bin/php-skeleton-install`, mais aucun binaire correspondant n’est déclaré. Variables `DB_*` différentes du générateur `MYSQL_*`. |
| `DOCUMENTATION/DOCKER.md` | Décrit PHP 8.1/MariaDB 10.11, `vhost.conf`, `php.ini` et variables `DB_*`, alors que le générateur produit PHP 8.3/MariaDB 11.3, `custom-php.ini` et `MYSQL_*`. |
| `DOCUMENTATION/SECURITY.md` | Présente `APP_ENV`, headers de sécurité et rate limiting comme attendus alors qu’ils ne sont pas générés. |
| `OPTIMISATIONS.md` | Décrit des composants inexistants comme s’ils étaient implémentés. |
| `CHANGELOG.md` | Plusieurs dates sont encore `2025-01-XX`. |

**Décision produit nécessaire :** choisir entre starter pédagogique et squelette production. Les deux positionnements ne peuvent pas partager les mêmes promesses.

## 8. Plan d’action priorisé

### P0 — avant toute release

1. Corriger le parcours local et générer/valider son `.env`.
2. Interdire Auth sans Doctrine ou fournir un provider non-Doctrine réellement fonctionnel.
3. Rendre l’installation non destructive et refuser les répertoires non vierges.
4. Supprimer les secrets par défaut, désactiver debug/display errors par défaut et ne pas publier MariaDB par défaut.
5. Corriger le lock et mettre à jour PHPUnit contre l’advisory haute.
6. Aligner les contraintes Composer générées sur les versions testées.
7. Réécrire `OPTIMISATIONS.md` et synchroniser les trois guides d’installation.

### P1 — fiabilisation

1. Ajouter un test end-to-end `create-project` en répertoire temporaire pour chaque combinaison locale/Docker/Doctrine/Auth.
2. Valider ports, noms, secrets et valeurs dotenv avant toute écriture.
3. Produire un README court dans le projet généré, conserver la licence et générer un lock cohérent.
4. Ajouter CI : PHP 8.1–8.5 supportés, `composer validate`, tests, lint, analyse statique et audit Composer.

### P2 — optimisation et refonte

1. Extraire les templates en fichiers versionnés.
2. Découper `Installer.php` par responsabilité.
3. Remplacer les chaînes shell par un runner avec `cwd` et arguments structurés.
4. Créer des images Docker dev/prod séparées, supprimer Xdebug/NVM du runtime et activer OPcache en production.
5. Durcir les uploads et réduire/masquer les données de logs.

## 9. Points positifs à conserver

- `declare(strict_types=1)` est présent dans le code PHP principal.
- `APP_SECRET` est généré avec une source cryptographiquement sûre.
- Le hook `post-create-project-cmd` est adapté à un skeleton Composer.
- Le Compose généré possède un healthcheck MariaDB et une dépendance conditionnée à son état sain.
- Le middleware CSRF est branché dans le bootstrap généré.
- L’intention de tester les injections de commandes est bonne, même si l’implémentation doit être simplifiée et les tests renforcés.

## Conclusion

Le problème principal n’est pas une micro-optimisation : c’est la fiabilité du générateur. Tant que les parcours local et Auth ne sont pas fonctionnels, que l’installateur supprime des fichiers et que la documentation décrit un produit différent, toute optimisation de détail est secondaire.

**Ordre recommandé :** vérité des artefacts générés → sécurité par défaut → tests d’intégration → refonte de l’installateur → optimisation Docker et runtime.
