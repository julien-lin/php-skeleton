# Plan d’optimisation de `php-skeleton` autour des bibliothèques existantes

**Date :** 5 octobre 2026  
**Statut :** référence de conception — implémentation progressive en cours  
**Objectif :** faire de `php-skeleton` un orchestrateur léger de l’écosystème `JulienLinard`, sans réimplémenter les fonctions déjà disponibles dans `core-php`, `php-router`, `php-validator`, `php-dotenv`, `php-cache`, `doctrine-php`, `auth-php`, `php-api` et `php-vision`.

## 1. Décision d’architecture proposée

Le skeleton doit devenir un **générateur de profils**, pas un framework parallèle.

```text
php-skeleton
├── profil web minimal
│   ├── core-php
│   └── php-router
├── profil web sécurisé
│   └── middlewares core-php + router
├── profil database
│   └── doctrine-php
├── profil auth
│   └── doctrine-php + auth-php
├── profil api
│   └── doctrine-php + php-api
├── profil templates Vision
│   └── php-vision, uniquement avec des templates Vision
└── recettes Docker
    └── générées séparément du runtime PHP
```

Principe directeur : **une application générée ne doit installer que les modules correspondant aux choix explicites de l’utilisateur**.

## 2. État des bibliothèques déjà disponibles

Versions observées dans l’espace de travail :

| Bibliothèque | Version locale | Rôle recommandé dans le skeleton | Décision |
|---|---:|---|---|
| `core-php` | 1.4.4 | Application, container DI, contrôleurs, vues, sessions, validation, cache, sécurité | Dépendance obligatoire |
| `php-router` | 1.4.4 | Routes par attributs, middlewares, groupes, URLs | Dépendance directe obligatoire |
| `php-validator` | 1.4.1 | Validation avancée | Transitive via Core ; directe seulement si l’application utilise son API native |
| `php-dotenv` | 1.0.2 | Chargement de `.env` | Transitive via Core ; ne pas la déclarer directement dans le profil de base |
| `php-cache` | 1.0.2 | Drivers File/Redis/Array, TTL, tags | Transitive via Core ; activer explicitement seulement si le code métier l’utilise |
| `doctrine-php` | 1.2.0 | EntityManager, repositories, migrations, QueryBuilder | Option `database` |
| `auth-php` | 1.3.0 | Authentification session, rôles, permissions, Remember Me | Option `auth`, dépendante de `database` |
| `php-api` | 1.3.2 | CRUD REST, filtres, pagination, RFC 7807, Swagger | Option `api`, dépendante de `database` |
| `php-vision` | 1.0.0 | Templates `{{ }}`, filtres, compilation, cache | Option `vision`, incompatible par défaut avec les templates PHP actuels |
| `php-carousel` | 1.1.1 | Composant UI | Ne pas inclure dans le skeleton backend de base |
| `php-docker-generator` | 1.0.8 | Générateur Docker autonome | Ne pas l’installer comme dépendance du skeleton ; mutualiser les recettes |

## 3. Dépendances à optimiser

### 3.1 Profil de base

Le projet généré doit viser ce socle fonctionnel :

- `php ^8.1` si la compatibilité PHP 8.1 reste impérative ;
- `julienlinard/core-php ^1.4` ;
- `julienlinard/php-router ^1.4` car le skeleton utilise directement les attributs, `Request` et `Response` ;
- PHPUnit corrigé dans la branche 11.5, au minimum `^11.5.50` selon l’advisory détectée dans le lock actuel.

Le skeleton ne doit pas déclarer directement `php-dotenv` ni `php-cache` dans ce profil : `core-php` les fournit déjà. La duplication de contraintes augmente le bruit du lock sans apporter de capacité.

### 3.2 Validation : réduire le couplage direct

Le bootstrap généré instancie actuellement directement `JulienLinard\Validator\Validator`, alors que `core-php` fournit déjà `JulienLinard\Core\Form\Validator`, wrapper de `php-validator`.

Plan recommandé :

1. utiliser `Core\Form\Validator` dans le profil de base ;
2. configurer sa locale via l’environnement ;
3. réserver la dépendance directe `php-validator` aux applications qui utilisent ses règles/API avancées directement ;
4. supprimer le binding redondant des deux validateurs si aucun besoin avancé n’est déclaré.

Gain : moins de contrats exposés dans le bootstrap et moins de risques de divergence entre les deux validateurs.

### 3.3 Profil `database`

Quand l’utilisateur choisit Doctrine, ajouter :

- `julienlinard/doctrine-php ^1.2` ;
- `ext-pdo` comme prérequis explicite ;
- configuration DB séparée pour local et Docker ;
- commandes issues du binaire officiel `vendor/bin/doctrine-migrate`.

Le skeleton doit générer un vrai point d’extension pour `EntityManager`, avec une seule instance dans le container. Il ne doit pas recopier un repository ou un système de migration maison.

### 3.4 Profil `auth`

`auth-php` dépend fonctionnellement de Doctrine dans sa version actuelle (`AuthManager` construit un `DatabaseUserProvider` avec un `EntityManager`). Le choix doit donc être :

```text
auth = oui  →  database = oui automatiquement
```

Dépendances recommandées :

- `julienlinard/auth-php ^1.3` ;
- `julienlinard/doctrine-php ^1.2` ;
- `ext-pdo`.

Le générateur doit également produire ou demander explicitement :

- une entité `User` implémentant `UserInterface` ;
- les champs nécessaires à `Authenticatable` ;
- la migration utilisateur ;
- la migration `remember_tokens` si Remember Me est activé ;
- l’enregistrement unique de `AuthManager` dans le container ;
- les middlewares `AuthMiddleware`, `GuestMiddleware`, `RoleMiddleware` et `PermissionMiddleware` uniquement dans ce profil.

### 3.5 Profil `api`

`php-api` est pertinent pour une variante API, mais pas dans le skeleton MVC minimal. Il doit être activé seulement si `api = oui`.

Avant de l’utiliser, il faut optimiser son propre `composer.json` :

- remplacer `julienlinard/core-php: "*"` par une contrainte stable compatible, idéalement `^1.4` ;
- remplacer `julienlinard/doctrine-php: "*"` par `^1.2` ;
- repasser `minimum-stability` à `stable` ;
- publier un lock testé ;
- documenter la compatibilité avec PHP 8.1+.

Le profil API doit générer un contrôleur API, une entité exemple et des routes API dédiées. Il ne doit pas installer Swagger ou une dépendance YAML supplémentaire si la documentation OpenAPI n’est pas activée.

### 3.6 Profil `vision`

`core-php` détecte automatiquement la présence de `php-vision` dans `View`. Cette détection est pratique mais dangereuse pour le skeleton actuel : les vues générées contiennent du PHP (`<?= ... ?>`), tandis que Vision attend sa propre syntaxe `{{ }}`/`{% %}`.

Décision recommandée :

- **ne pas installer Vision dans le profil de base** ;
- choisir explicitement un moteur de vue par profil ;
- si Vision est choisi, générer des fichiers `.html.vis` ou `.vis` avec syntaxe Vision et auto-escape ;
- tester que l’installation de Vision ne transforme pas silencieusement les templates PHP en texte brut.

Il faut éviter le comportement « la présence d’une dépendance change implicitement le moteur de rendu ».

### 3.7 `php-carousel`

`php-carousel` est un composant UI indépendant, sans dépendance runtime externe, mais il exige PHP 8.2+. L’ajouter au profil de base ferait monter inutilement la contrainte PHP du skeleton de 8.1 à 8.2.

Décision recommandée : le garder dans un profil `ui` ou dans un exemple d’intégration séparé. Les intégrations Twig/Blade restent optionnelles et ne doivent pas être installées avec le skeleton MVC PHP natif.

### 3.8 `php-docker-generator`

`php-docker-generator` est lui-même un projet avec un hook `post-create-project-cmd`. Le déclarer comme dépendance du skeleton créerait une responsabilité et un cycle de génération confus.

Décision recommandée :

- ne pas le mettre dans `require` ;
- comparer ses recettes avec celles du skeleton ;
- extraire ultérieurement un paquet commun de recettes Docker ou choisir un seul générateur maître ;
- garder l’installation Docker comme un profil explicitement sélectionné.

## 4. Point bloquant entre `core-php` et `php-router`

Avant d’activer les optimisations de sécurité et de performance, il faut aligner les contrats de middleware :

- `php-router::Router::addMiddleware()` attend `JulienLinard\Router\Middleware` ;
- `CsrfMiddleware` respecte ce contrat ;
- plusieurs middlewares de `core-php` (`SecurityHeadersMiddleware`, `RateLimitMiddleware`, `CompressionMiddleware`) implémentent `Core\Middleware\MiddlewareInterface`, qui est une interface distincte.

De plus, `SecurityHeadersMiddleware` expose `applyToResponse()` et `CompressionMiddleware` expose `compress()`, mais le pipeline actuel du router ne semble pas appliquer automatiquement ces étapes post-réponse.

### Décision nécessaire avant intégration

Choisir une seule stratégie :

1. **Aligner les bibliothèques** : faire de l’interface Core une extension du contrat Router et ajouter une vraie étape after-response dans le pipeline ;
2. **Créer un adaptateur dans le skeleton** : solution temporaire, à éviter comme architecture définitive ;
3. **Ne pas activer ces middlewares** tant que leur contrat n’est pas compatible.

La stratégie recommandée est la première, car elle bénéficie à tous les projets utilisant `core-php` et `php-router`, pas seulement au skeleton.

## 5. Bootstrap cible du projet généré

Le bootstrap doit utiliser les services existants dans cet ordre :

1. `Application::create()` avec un chemin absolu fiable ;
2. `Application::loadEnv()` via `php-dotenv` transitif ;
3. `Application::loadConfig('config')` ;
4. validation de l’environnement ;
5. configuration du logger sans recopier le comportement du Core ;
6. configuration des vues avec `View::configureCache()` si le cache est activé ;
7. enregistrement du container et des services métier ;
8. middlewares de sécurité compatibles ;
9. routes via attributs `php-router` ;
10. `Application::handle()`.

### Middlewares par profil

| Profil | Middlewares recommandés |
|---|---|
| Base | Groupe web : CSRF pour les formulaires |
| Web sécurisé | Global : headers/compression ; groupe web : CSRF, validation, rate limit |
| API | Groupe API : CORS configuré explicitement, validation, rate limit ; pas de CSRF |
| Auth | Auth/Guest/Role/Permission au niveau des routes concernées |
| Production | Compression après résolution de réponse, si le pipeline le supporte réellement |

Les middlewares ne doivent pas être ajoutés globalement sans tenir compte de leur domaine : le CSRF ne doit pas protéger mécaniquement toutes les routes API, et le rate limit doit être plus strict sur l’authentification que sur une page publique.

## 6. Cache et performance : utiliser les capacités existantes

### 6.1 Cache des vues

Utiliser le cache déjà fourni par `core-php` :

- `View::configureCache('storage/cache/views', $ttl)` ;
- `View::setCacheEnabled(true)` en production ;
- désactivation ou TTL court en développement ;
- nettoyage planifié des fichiers expirés.

Ne pas créer un `CacheService` statique maison comme annoncé dans `OPTIMISATIONS.md`.

### 6.2 Cache applicatif

Utiliser `php-cache` uniquement dans un service métier injecté :

- driver `array` pour les tests ;
- driver `file` pour le développement simple ;
- driver Redis en production si l’extension et le service sont réellement disponibles ;
- tags pour invalider les données liées à une entité ;
- clés versionnées et TTL explicite.

Le profil de base ne doit pas ajouter Redis ni Memcached. L’activation d’un driver doit aussi générer le service Docker et les extensions nécessaires.

### 6.3 Doctrine et cache de requêtes

Quand Doctrine est activé :

- réutiliser son `EntityManager` singleton ;
- exploiter son QueryBuilder et son cache de requêtes existant ;
- éviter les requêtes dans les contrôleurs ;
- ajouter des repositories injectés ;
- mesurer les requêtes avant d’ajouter un cache applicatif.

### 6.4 Docker runtime

Séparer les profils Docker :

- `dev` : Xdebug, outils CLI et éventuellement Node ;
- `prod` : pas de Xdebug, pas de NVM, extensions minimales, OPcache, utilisateur non-root si compatible avec Apache ;
- MariaDB non exposée par défaut sur l’hôte ;
- healthcheck conservé ;
- versions d’images et Composer pinées.

## 7. Refonte du générateur sans réimplémenter les libs

### Phase A — Contrat et dépendances

- formaliser les profils `base`, `secure`, `database`, `auth`, `api`, `vision`, `ui` ;
- aligner `core-php` et `php-router` sur le contrat middleware ;
- corriger les contraintes de `php-api` ;
- mettre à jour le lock et PHPUnit ;
- supprimer les contraintes `*` des modules consommés par le skeleton.

### Phase B — Templates et configuration

- sortir tous les heredocs de `Installer.php` vers des templates versionnés ;
- générer un `composer.json` par profil à partir d’une matrice contrôlée ;
- conserver la licence et un README minimal dans le projet généré ;
- générer `.env.example` selon le profil local ou Docker ;
- valider et sérialiser les valeurs `.env`.

### Phase C — Bootstrap minimal

- retirer les services générés qui doublonnent Core ;
- conserver seulement la configuration spécifique au projet ;
- utiliser `Core\Form\Validator` par défaut ;
- brancher View cache, logger, routes et middlewares via les APIs publiques existantes ;
- rendre Doctrine/Auth explicites et testables.

### Phase D — Installation sûre

- ne plus détruire le projet source ;
- refuser un répertoire cible non vide sans option explicite ;
- remplacer `exec()`/`shell_exec()` par un runner à arguments structurés ;
- supporter un mode non interactif pour CI ;
- rendre l’installation relançable et transactionnelle autant que possible.

### Phase E — Tests de compatibilité

Pour chaque profil, le pipeline doit vérifier :

1. `composer validate --strict` ;
2. `composer install --prefer-dist --no-interaction` ;
3. lint de tous les PHP générés ;
4. `docker compose config` pour le profil Docker ;
5. démarrage HTTP smoke test ;
6. présence/absence attendue des packages ;
7. résolution du container ;
8. rendu d’une vue ;
9. scénario Doctrine ;
10. login/logout et middleware Auth si le profil est activé.

## 8. Matrice de dépendances cible

| Profil | `core-php` | `php-router` | `php-validator` | `php-dotenv` | `php-cache` | `doctrine-php` | `auth-php` | `php-api` | `php-vision` | `php-carousel` |
|---|---|---|---|---|---|---|---|---|---|---|
| Base | direct | direct | transitive | transitive | transitive | — | — | — | — | — |
| Secure | direct | direct | transitive | transitive | transitive | — | — | — | — | — |
| Database | direct | direct | transitive | transitive | transitive | direct | — | — | — | — |
| Auth | direct | direct | transitive | transitive | transitive | direct | direct | — | — | — |
| API | direct | direct | transitive | transitive | transitive | direct | optionnel | direct | — | — |
| Vision | direct | direct | transitive | transitive | transitive | — | — | — | direct | — |
| UI | direct | direct | transitive | transitive | transitive | — | — | — | — | direct |

## 9. Critères de réussite

Le plan sera considéré comme réussi quand :

- le profil base démarre sans Doctrine, Auth, API, Vision ou Carousel ;
- le profil local génère un `.env.example` utilisable et démarre réellement ;
- Auth implique automatiquement Doctrine et une entité User valide ;
- aucun package n’est déclaré avec `*` dans la chaîne du skeleton ;
- `composer validate --strict` et `composer audit` passent ;
- les middlewares Core sont réellement compatibles avec le pipeline Router ;
- le cache de vues et le cache applicatif reposent sur `core-php`/`php-cache`, sans service maison redondant ;
- l’image production ne contient ni Xdebug ni NVM ;
- l’installation ne supprime ni licence, ni README, ni lock sans décision explicite ;
- chaque profil dispose d’un smoke test automatisé.

## 10. Priorités finales

### P0 — avant toute nouvelle release

1. Corriger les contraintes Composer et le lock.
2. Mettre PHPUnit à jour contre l’advisory haute.
3. Corriger le parcours local et le couplage Auth/Doctrine.
4. Décider et corriger le contrat middleware Core/Router.
5. Supprimer les dépendances wildcard des bibliothèques consommées.

### P1 — stabilisation du skeleton

1. Introduire les profils de dépendances.
2. Sortir les templates de `Installer.php`.
3. Utiliser les APIs Core existantes pour validation, cache, sessions, erreurs et vues.
4. Ajouter les tests d’installation end-to-end.

### P2 — performance et distribution

1. Images Docker dev/prod séparées.
2. Cache de vues activé en production.
3. Cache applicatif configurable par driver.
4. API, Vision et UI installés uniquement à la demande.
5. CI complète sur les profils supportés.

## Conclusion

La meilleure optimisation consiste à réduire le code du skeleton : `core-php` doit gérer l’application, `php-router` le routage, `php-validator` la validation via Core, `php-dotenv` la configuration, `php-cache` le cache et Doctrine/Auth/API les profils spécialisés.

Le travail prioritaire n’est donc pas d’ajouter une nouvelle couche `CacheService`, `Repository` ou middleware maison. Il faut d’abord aligner les contrats de bibliothèques, resserrer les dépendances, générer des profils cohérents et tester chaque combinaison.
