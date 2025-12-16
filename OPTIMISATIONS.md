# 🚀 Optimisations du PHP Skeleton

Ce document détaille les optimisations apportées à l'application générée par le skeleton.

## ✨ Améliorations Implémentées

### 1. ✅ Service de Cache Centralisé
**Fichier** : `src/Service/CacheService.php`

Un service centralisé pour gérer le cache de manière simple et consistante.

**Fonctionnalités** :
- `get($key, $default)` - Récupère une valeur du cache
- `put($key, $value, $ttl)` - Stocke une valeur
- `remember($key, $callback, $ttl)` - Pattern courant : cache ou exécute un callback
- `forget($key)` - Supprime une clé
- `flush()` - Vide le cache entier

**Exemple d'usage** :
```php
// Mettre en cache une valeur coûteuse
$users = CacheService::remember('all_users', function() {
    return User::all();
}, 3600); // Cache pendant 1 heure
```

---

### 2. ✅ ExampleController Complet
**Fichier** : `src/Controller/ExampleController.php`

Un contrôleur exemple qui démontre les principales features :
- Validation de formulaire avec php-validator
- Utilisation du cache
- Réponses JSON (RESTful API)
- Redirections
- Gestion des erreurs

**Routes** :
- `GET /examples` - Liste des exemples
- `GET|POST /examples/form` - Formulaire avec validation
- `GET /examples/cache` - Démonstration du cache
- `GET /examples/json` - Réponse JSON

---

### 3. ✅ UserRepository (si Doctrine installé)
**Fichier** : `src/Repository/UserRepository.php`

Repository pattern pour les requêtes sur l'entité User.

**Méthodes** :
- `findAll()` - Tous les utilisateurs
- `findById($id)` - Utilisateur par ID
- `findByEmail($email)` - Utilisateur par email
- `getPaginated($page, $perPage)` - Pagination
- `count()` - Nombre total
- `create($data)` - Créer un utilisateur
- `update($user, $data)` - Modifier un utilisateur
- `delete($user)` - Supprimer un utilisateur

**Exemple** :
```php
$userRepo = new UserRepository();
$users = $userRepo->getPaginated(1, 15); // Page 1, 15 par page
$user = $userRepo->findByEmail('john@example.com');
```

---

### 4. ✅ Migration Initiale Doctrine (si Auth installé)
**Fichier** : `db/migrations/Version[timestamp]_CreateUsersTable.php`

Migration SQL pour créer la table `users` avec les colonnes appropriées :
- id
- firstname, lastname, email
- password
- active (booléen)
- email_verified_at
- created_at, updated_at, deleted_at
- Index sur email, active, created_at

---

### 5. ✅ Page d'Accueil Améliorée
**Fichier** : `views/home/index.html.php`

Une page d'accueil moderne et informative avec :
- Section hero avec gradient
- Statut d'installation
- Packages installés
- Liens rapides vers les exemples et la documentation
- Vue d'ensemble des prochaines étapes
- Highlights des features intégrées
- Exemple de code

---

### 6. ✅ Templates pour les Exemples
**Fichiers** : `views/examples/index.html.php`, `views/examples/form.html.php`

Templates pour démontrer la validation de formulaire :
- Affichage des erreurs de validation
- Re-remplissage du formulaire avec les données saisies
- Styling professionnel avec Tailwind CSS

---

### 7. ✅ Intégration du CacheService
Le CacheService est **automatiquement initialisé** dans le container DI lors du bootstrap.

```php
// Dans le generateIndexContent()
$container->singleton(\App\Service\CacheService::class, function() use ($container) {
    $cache = $container->make(Cache::class);
    CacheService::initialize($cache);
    return CacheService::class;
});
```

---

## 📊 Avant vs Après

### Avant l'optimisation
- ❌ Cache peu exploité (infrastructure seule)
- ❌ Pas d'exemples de contrôleurs complets
- ❌ Pas de Repository exemple
- ❌ Migration vide ou manquante
- ❌ Page d'accueil minimaliste

### Après l'optimisation
- ✅ Cache facile à utiliser via CacheService
- ✅ ExampleController complet avec 5+ routes
- ✅ UserRepository avec 8+ méthodes
- ✅ Migration initiale prête à être exécutée
- ✅ Page d'accueil riche et informatique

---

## 🚀 Comment Utiliser les Nouvelles Features

### 1. Utiliser le Cache
```php
// Dans un contrôleur ou service
use App\Service\CacheService;

class ProductController extends Controller
{
    public function index()
    {
        $products = CacheService::remember('all_products', function() {
            return Product::all();
        }, 3600); // Cache 1h
        
        return $this->view('products/index', ['products' => $products]);
    }
}
```

### 2. Utiliser le UserRepository
```php
// Dans un contrôleur
class UserController extends Controller
{
    public function show()
    {
        $userRepo = new UserRepository();
        $user = $userRepo->findById($this->request->getParam('id'));
        
        return $this->view('users/show', ['user' => $user]);
    }
}
```

### 3. Copier le Pattern pour d'Autres Entités
```php
// Créer YourEntityRepository.php en suivant le modèle UserRepository
// Adapter les méthodes aux spécificités de votre entité
```

### 4. Exécuter les Migrations
```bash
# Avec Doctrine installé
composer run-script doctrine:migrate

# Ou manuellement
php db/migrations/Version20240101000000_CreateUsersTable.php
```

---

## 📚 Structure Résultante

```
www/
├── src/
│   ├── Controller/
│   │   ├── HomeController.php       (existant)
│   │   └── ExampleController.php    (NOUVEAU) ✨
│   ├── Repository/
│   │   └── UserRepository.php       (NOUVEAU) ✨
│   ├── Service/
│   │   ├── BootstrapService.php     (existant)
│   │   ├── EnvValidator.php         (existant)
│   │   ├── EventListenerService.php (existant)
│   │   └── CacheService.php         (NOUVEAU) ✨
│   └── Entity/
│       └── User.php                 (si Auth installé)
├── views/
│   ├── home/
│   │   └── index.html.php           (AMÉLIORÉ) 🎨
│   ├── examples/                    (NOUVEAU) ✨
│   │   ├── index.html.php
│   │   └── form.html.php
│   └── _templates/
│       ├── _header.html.php
│       └── _footer.html.php
├── db/
│   └── migrations/
│       └── Version[timestamp]_CreateUsersTable.php  (NOUVEAU) ✨
├── public/
│   └── index.php                    (AMÉLIORÉ) 🎨
└── config/
    └── database.php
```

---

## 🎯 Prochaines Étapes

1. **Explorer les exemples**
   - Accédez à `/examples` pour voir les démonstrations

2. **Créer vos propres contrôleurs**
   - Copiez le pattern d'ExampleController
   - Adaptez les routes et logiques à vos besoins

3. **Créer des repositories pour vos entités**
   - Copiez UserRepository
   - Adapteziez les méthodes à vos besoins

4. **Utiliser le cache stratégiquement**
   - Identifiez les opérations coûteuses
   - Mettez-les en cache avec CacheService

5. **Exécuter les migrations**
   - Si Auth est installé, exécutez la migration users
   - Créez des migrations pour vos propres tables

---

## 💡 Bonnes Pratiques

### Cache
- ✅ Utilisez `remember()` pour les opérations coûteuses
- ✅ Définissez un TTL approprié (300-3600 secondes)
- ✅ Invalidez le cache quand les données changent
- ❌ N'abusez pas du cache (maintenabilité)

### Contrôleurs
- ✅ Gardez les contrôleurs minces
- ✅ Mettez la logique métier dans les services/repositories
- ✅ Validez toujours les inputs utilisateur
- ❌ N'accédez pas directement à la BD depuis les contrôleurs

### Repositories
- ✅ Centralisez les requêtes BD
- ✅ Créez des méthodes spécifiques pour chaque requête
- ✅ Réutilisez les repositories
- ❌ Ne mélangez pas la logique métier avec l'accès aux données

---

## 🐛 Troubleshooting

### Le cache ne fonctionne pas
- Vérifiez que CacheService est bien initialisé
- Vérifiez que le répertoire de cache est accessible en écriture
- Utilisez `CacheService::flush()` pour vider le cache

### Les routes des exemples ne sont pas trouvées
- Vérifiez que ExampleController est enregistré dans index.php
- Vérifiez la syntaxe des attributs #[Route]

### Les migrations ne s'exécutent pas
- Vérifiez que Doctrine est installé
- Vérifiez les permissions du répertoire `db/migrations/`
- Vérifiez la connexion à la base de données

---

## 📖 Documentation Utile

- [Core PHP Framework](https://github.com/julien-lin/core-php)
- [PHP Router](https://github.com/julien-lin/php-router)
- [PHP Validator](https://packagist.org/packages/julienlinard/php-validator)
- [Doctrine PHP](https://github.com/julien-lin/doctrine-php)
- [Auth PHP](https://github.com/julien-lin/auth-php)

---

**Date de création** : 15 décembre 2025  
**Version du Skeleton** : 1.5.8+optimisé
