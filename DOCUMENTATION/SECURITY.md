# Sécurité

Ce document décrit les mesures de sécurité implémentées dans le skeleton PHP et les bonnes pratiques à suivre.

## Mesures de Sécurité Implémentées

### 1. Sécurisation de l'Exécution de Commandes

Le script d'installation utilise des méthodes sécurisées pour exécuter des commandes système :

#### `safeExec()`

Méthode sécurisée pour exécuter des commandes via `exec()` :

- **Whitelist de commandes** : Seules les commandes `composer` et `which` sont autorisées
- **Protection contre le path traversal** : Rejet des chemins contenant `..`
- **Protection contre les chemins système** : Rejet des chemins sensibles (`/etc`, `/bin`, `/usr/bin`, etc.)
- **Protection contre les injections** : Rejet des caractères dangereux (`;`, `&`, `|`, `` ` ``, `$`, `<`, `>`)
- **Autorisation de `&&`** : Autorisé uniquement dans le contexte de `cd` pour les commandes composées

#### `safeShellExec()`

Méthode sécurisée pour exécuter des commandes via `shell_exec()` :

- **Whitelist limitée** : Seule la commande `which` est autorisée
- **Validation des arguments** : Protection contre le path traversal et les caractères dangereux

### 2. Gestion des Sessions

Le skeleton configure des sessions sécurisées :

- **Cookies sécurisés** : `httponly`, `secure` (en production), `samesite=Strict`
- **Régénération d'ID** : Régénération automatique toutes les 15 minutes
- **Protection CSRF** : Middleware CSRF disponible

### 3. Configuration Sécurisée par Défaut

#### Variables d'Environnement

- **APP_SECRET** : Généré automatiquement avec une valeur aléatoire forte
- **APP_DEBUG** : Désactivé par défaut en production
- **APP_ENV** : Permet de distinguer les environnements

#### Headers de Sécurité

Le middleware `SecurityHeadersMiddleware` ajoute automatiquement :

- `Content-Security-Policy`
- `Strict-Transport-Security`
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy`
- `X-XSS-Protection: 1; mode=block`

### 4. Protection contre les Injections

#### SQL Injection

- Utilisation de requêtes préparées via PDO
- Doctrine utilise des requêtes préparées par défaut

#### XSS (Cross-Site Scripting)

- Échappement automatique dans les vues
- Sanitization des données utilisateur

#### CSRF (Cross-Site Request Forgery)

- Middleware CSRF disponible
- Tokens CSRF pour les formulaires

## Bonnes Pratiques

### 1. Variables d'Environnement

**✅ À faire** :
- Utiliser `.env` pour les variables sensibles
- Ajouter `.env` au `.gitignore`
- Utiliser `.env.example` comme template
- Générer un `APP_SECRET` fort et unique

**❌ À éviter** :
- Commiter `.env` dans Git
- Utiliser des secrets faibles
- Partager le même `APP_SECRET` entre environnements

### 2. Mots de Passe

**✅ À faire** :
- Utiliser des algorithmes de hachage sécurisés (BCRYPT, Argon2ID)
- Implémenter la ré-hachage automatique si nécessaire
- Utiliser des mots de passe forts (minimum 12 caractères)

**❌ À éviter** :
- Stocker les mots de passe en clair
- Utiliser MD5 ou SHA1 pour les mots de passe
- Réutiliser les mots de passe entre services

### 3. Base de Données

**✅ À faire** :
- Utiliser des utilisateurs avec des permissions limitées
- Changer les mots de passe par défaut
- Utiliser des connexions chiffrées (SSL/TLS)
- Faire des sauvegardes régulières

**❌ À éviter** :
- Utiliser l'utilisateur `root` pour l'application
- Exposer la base de données sur Internet sans protection
- Stocker des données sensibles non chiffrées

### 4. Authentification

**✅ À faire** :
- Implémenter la limitation de taux (rate limiting)
- Utiliser des sessions sécurisées
- Implémenter la déconnexion automatique après inactivité
- Utiliser HTTPS en production

**❌ À éviter** :
- Stocker les tokens d'authentification dans les cookies non sécurisés
- Permettre des sessions infinies
- Exposer les informations d'authentification dans les logs

### 5. Déploiement

**✅ À faire** :
- Désactiver `APP_DEBUG` en production
- Utiliser HTTPS uniquement
- Configurer correctement les permissions de fichiers
- Mettre à jour régulièrement les dépendances

**❌ À éviter** :
- Exposer les fichiers de configuration sensibles
- Utiliser des permissions trop permissives (777)
- Déployer avec `APP_DEBUG=true`

## Checklist de Sécurité

Avant de déployer en production, vérifiez :

- [ ] `APP_DEBUG=false` dans `.env`
- [ ] `APP_ENV=production` dans `.env`
- [ ] `APP_SECRET` est unique et fort
- [ ] HTTPS est configuré et forcé
- [ ] Les mots de passe de base de données sont forts
- [ ] Les permissions de fichiers sont correctes (755 pour les dossiers, 644 pour les fichiers)
- [ ] Les logs ne contiennent pas d'informations sensibles
- [ ] Les dépendances sont à jour
- [ ] Les headers de sécurité sont activés
- [ ] Le rate limiting est configuré
- [ ] Les sauvegardes de base de données sont planifiées

## Audit de Sécurité

### Outils Recommandés

- **Composer Audit** : Vérifier les vulnérabilités des dépendances
  ```bash
  composer audit
  ```

- **PHP Security Checker** : Vérifier les vulnérabilités PHP
  ```bash
  composer require sensiolabs/security-checker
  ```

- **OWASP ZAP** : Scanner de sécurité web
- **Burp Suite** : Test de pénétration

### Tests de Sécurité

Le skeleton inclut des tests de sécurité dans `tests/InstallerSecurityTest.php` :

- Tests d'injection de commandes
- Tests de path traversal
- Tests de validation des entrées

Exécutez les tests :

```bash
vendor/bin/phpunit tests/InstallerSecurityTest.php
```

## Signaler une Vulnérabilité

Si vous découvrez une vulnérabilité de sécurité, **ne créez pas d'issue publique**. Contactez plutôt :

- **Email** : julien.linard.dev@gmail.com
- **PGP Key** : [À fournir si nécessaire]

Nous répondrons dans les 48 heures et publierons un correctif si nécessaire.

## Ressources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)
- [Composer Security Advisories](https://github.com/FriendsOfPHP/security-advisories)

