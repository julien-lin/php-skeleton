# Configuration Docker

Ce guide explique la configuration Docker du skeleton PHP et comment l'utiliser.

## Architecture Docker

Le skeleton génère deux configurations Docker distinctes :

- `docker-compose.yml` : développement, avec montage du code source et Composer disponible dans le conteneur ;
- `docker-compose.prod.yml` : production, avec image construite depuis le projet, dépendances installées en `--no-dev`, OPcache activé et aucun montage du code source.

Les deux configurations peuvent utiliser les mêmes services :

- **Apache** : Serveur web avec PHP 8.1+
- **MariaDB** : Base de données

## Structure des Fichiers Docker

```
mon-projet/
├── docker-compose.yml     # Configuration développement
├── docker-compose.prod.yml # Configuration production
├── apache/
│   ├── Dockerfile         # Image développement
│   ├── Dockerfile.prod    # Image production multi-stage
│   ├── custom-php.ini     # PHP développement
│   └── custom-php-prod.ini # PHP production
└── www/                   # Code source de l'application
```

## Développement

Le fichier `docker-compose.yml` monte `./www` dans `/var/www/html`. Les modifications de code sont donc immédiatement visibles et Composer reste disponible dans le conteneur.

```bash
docker compose up -d --build
docker compose exec apache_app composer install
docker compose logs -f apache_app
```

Le fichier `www/.env` est utilisé par l'application. Pour ce mode, gardez `APP_DEBUG=1`.

## Production

Le fichier `docker-compose.prod.yml` utilise `apache/Dockerfile.prod`. L'image installe les dépendances depuis `www/composer.lock` avec `--no-dev`, puis copie l'application dans l'image. Le code source n'est pas monté en volume et Composer n'est pas inclus dans l'image finale.

Préparez la configuration de production :

```bash
cp www/.env.production.example www/.env
# Renseignez APP_SECRET et les identifiants de base de données.
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml ps
```

Le compose impose `APP_ENV=production` et `APP_DEBUG=0`. Les seuls volumes Apache sont `storage`, les uploads et la configuration PHP en lecture seule. Le fichier `www/.env` est injecté par Compose et n'est pas copié dans l'image.

## Service MariaDB

```yaml
mariadb_app:
  image: mariadb:10.11
  container_name: ${MARIADB_CONTAINER_NAME:-mariadb_app}
  environment:
    MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASS}
    MYSQL_DATABASE: ${DB_NAME}
    MYSQL_USER: ${DB_USER}
    MYSQL_PASSWORD: ${DB_PASS}
  volumes:
    - mariadb_data:/var/lib/mysql
```

## Image Docker Apache

L'image de développement (`apache/Dockerfile`) est basée sur `php:8.3-apache` et inclut :

- PHP 8.3 avec extensions nécessaires
- Composer installé globalement
- Configuration Apache optimisée
- Extensions PHP : `pdo`, `pdo_mysql`, `mysqli`, `intl`, `gd`, `opcache`

L'image de production (`apache/Dockerfile.prod`) utilise une étape Composer séparée. L'étape finale ne contient ni Composer ni les dépendances de développement.

## Commandes Docker

### Démarrer les Conteneurs

```bash
docker compose up -d
```

Pour la production, utilisez explicitement le fichier dédié :

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

L'option `-d` démarre les conteneurs en arrière-plan.

### Arrêter les Conteneurs

```bash
docker compose down
```

Pour supprimer aussi les volumes :

```bash
docker compose down -v
```

### Voir les Logs

```bash
# Tous les services
docker compose logs

# Un service spécifique
docker compose logs apache_app
docker compose logs mariadb_app

# Suivre les logs en temps réel
docker compose logs -f apache_app
```

### Redémarrer un Service

```bash
docker compose restart apache_app
docker compose restart mariadb_app
```

### Reconstruire l'Image

```bash
docker compose build
docker compose up -d
```

## Aliases Utiles

Le fichier `aliases.sh` fournit des aliases pour faciliter l'utilisation :

```bash
source aliases.sh

# Exécuter composer dans le conteneur
ccomposer require package/name
ccomposer install
ccomposer update

# Accéder au shell du conteneur
capache    # Shell du conteneur Apache
cmariadb   # Shell du conteneur MariaDB

# Gestion de la base de données
db-export  # Exporter la base de données
db-import  # Importer la base de données
```

## Gestion de la Base de Données

### Connexion à MariaDB

```bash
# Via l'alias
cmariadb

# Ou directement
docker compose exec mariadb_app bash
mysql -u root -p
```

### Export de la Base de Données

```bash
# Via l'alias
db-export

# Ou directement
docker compose exec mariadb_app /docker-entrypoint-initdb.d/backup.sh
```

### Import de la Base de Données

```bash
# Via l'alias
db-import

# Ou directement
docker compose exec mariadb_app /docker-entrypoint-initdb.d/restore.sh
```

### Accès depuis l'Extérieur

En développement, MariaDB est accessible uniquement depuis la machine hôte sur `127.0.0.1:3306` (configurable via `MARIADB_PORT` dans `.env`). Elle n’est pas publiée sur toutes les interfaces réseau.

Connexion depuis un client MySQL :

```bash
mysql -h 127.0.0.1 -P 3306 -u mon_user -p mon_app
```

## Volumes Docker

En développement, les volumes Docker sont utilisés pour :

- **Code source** : `./www` monté dans `/var/www/html`
- **Configuration PHP** : `./apache/custom-php.ini` monté dans `/usr/local/etc/php/conf.d/custom-php.ini`
- **Données MariaDB** : volume nommé `mysql` pour la persistance

En production, le code est intégré à l'image. Seuls `storage`, `public/uploads`, `custom-php-prod.ini` et le volume de base de données sont persistants ou montés.

### Sauvegarder les Données

```bash
# Créer une sauvegarde du volume
docker run --rm -v mon-projet_mariadb_data:/data -v $(pwd):/backup alpine tar czf /backup/mariadb-backup.tar.gz /data
```

### Restaurer les Données

```bash
# Restaurer depuis une sauvegarde
docker run --rm -v mon-projet_mariadb_data:/data -v $(pwd):/backup alpine tar xzf /backup/mariadb-backup.tar.gz -C /
```

## Personnalisation

### Modifier la Configuration Apache

Éditez le `Dockerfile` correspondant au mode utilisé et reconstruisez l'image :

```bash
docker compose build --no-cache
docker compose up -d

# Production
docker compose -f docker-compose.prod.yml up -d --build
```

### Modifier la Configuration PHP

Éditez `apache/custom-php.ini` en développement ou `apache/custom-php-prod.ini` en production :

```bash
docker compose restart apache_app
docker compose -f docker-compose.prod.yml up -d --build
```

### Ajouter des Extensions PHP

Modifiez le `Dockerfile` :

```dockerfile
RUN docker-php-ext-install pdo_mysql
RUN docker-php-ext-install mysqli
```

Puis reconstruisez l'image :

```bash
docker compose build
docker compose up -d
```

## Dépannage

### Le Conteneur ne Démarre pas

Vérifiez les logs :

```bash
docker compose logs apache_app
```

Vérifiez que les ports ne sont pas déjà utilisés :

```bash
lsof -i :8080  # Port Apache
lsof -i :3306  # Port MariaDB
```

### Les Permissions ne sont pas Correctes

Le script `fix-permissions.sh` est créé automatiquement. Exécutez-le :

```bash
./fix-permissions.sh
```

Ou manuellement :

```bash
sudo chown -R www-data:www-data www/storage
sudo chmod -R 755 www/storage
```

### Composer ne Fonctionne pas dans le Conteneur

Vérifiez que Composer est installé :

```bash
docker compose exec apache_app composer --version
```

Si Composer n'est pas installé, modifiez le `Dockerfile` pour l'ajouter.

### La Base de Données n'est pas Accessible

Vérifiez les variables d'environnement dans `.env` :

```env
DB_HOST=mariadb_app
DB_PORT=3306
DB_NAME=mon_app
DB_USER=mon_user
DB_PASS=mon_password
```

Vérifiez que le conteneur MariaDB est démarré :

```bash
docker compose ps
```

### Les Modifications du Code ne sont pas Prises en Compte

Vérifiez que le volume est correctement monté :

```bash
docker compose exec apache_app ls -la /var/www/html
```

Redémarrez le conteneur si nécessaire :

```bash
docker compose restart apache_app
```

## Production

### Recommandations pour la Production

1. **Utiliser des images spécifiques** : Éviter `latest`, utiliser des tags de version
2. **Limiter les ressources** : Ajouter des limites CPU et mémoire
3. **Utiliser HTTPS** : Configurer un reverse proxy (nginx, Traefik)
4. **Sécuriser les ports** : Ne pas exposer les ports de base de données publiquement
5. **Utiliser des secrets** : Ne pas stocker les mots de passe dans `docker-compose.yml`
6. **Surveiller les logs** : Configurer la rotation des logs
7. **Faire des sauvegardes** : Automatiser les sauvegardes de base de données

### Exemple de Configuration Production

```yaml
services:
  apache_app:
    deploy:
      resources:
        limits:
          cpus: '1'
          memory: 512M
        reservations:
          cpus: '0.5'
          memory: 256M
    restart: unless-stopped
```

## Ressources

- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Documentation](https://docs.docker.com/compose/)
- [PHP Docker Images](https://hub.docker.com/_/php)
- [MariaDB Docker Images](https://hub.docker.com/_/mariadb)
