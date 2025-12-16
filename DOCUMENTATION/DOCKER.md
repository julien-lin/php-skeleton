# Configuration Docker

Ce guide explique la configuration Docker du skeleton PHP et comment l'utiliser.

## Architecture Docker

Le skeleton utilise Docker Compose pour orchestrer plusieurs conteneurs :

- **Apache** : Serveur web avec PHP 8.1+
- **MariaDB** : Base de données

## Structure des Fichiers Docker

```
mon-projet/
├── docker-compose.yml     # Configuration Docker Compose
├── Dockerfile             # Image Docker Apache
├── php.ini                # Configuration PHP personnalisée
├── apache/
│   └── vhost.conf         # Configuration Apache Virtual Host
└── www/                   # Code source monté dans le conteneur
```

## Configuration Docker Compose

Le fichier `docker-compose.yml` définit les services :

### Service Apache

```yaml
apache_app:
  build:
    context: .
    dockerfile: Dockerfile
  container_name: ${APACHE_CONTAINER_NAME:-apache_app}
  ports:
    - "${APACHE_PORT:-8080}:80"
  volumes:
    - ./www:/var/www/html
    - ./apache/vhost.conf:/etc/apache2/sites-available/000-default.conf
    - ./php.ini:/usr/local/etc/php/conf.d/custom.ini
  environment:
    - APACHE_DOCUMENT_ROOT=/var/www/html/public
  depends_on:
    - mariadb_app
```

### Service MariaDB

```yaml
mariadb_app:
  image: mariadb:10.11
  container_name: ${MARIADB_CONTAINER_NAME:-mariadb_app}
  ports:
    - "${MARIADB_PORT:-3306}:3306"
  environment:
    MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASS}
    MYSQL_DATABASE: ${DB_NAME}
    MYSQL_USER: ${DB_USER}
    MYSQL_PASSWORD: ${DB_PASS}
  volumes:
    - mariadb_data:/var/lib/mysql
    - ./db/backup.sh:/docker-entrypoint-initdb.d/backup.sh
    - ./db/restore.sh:/docker-entrypoint-initdb.d/restore.sh
```

## Image Docker Apache

Le `Dockerfile` est basé sur `php:8.1-apache` et inclut :

- PHP 8.1+ avec extensions nécessaires
- Composer installé globalement
- Configuration Apache optimisée
- Extensions PHP : `pdo`, `pdo_mysql`, `mysqli`, `mbstring`, `xml`, `curl`, `zip`, `gd`

## Commandes Docker

### Démarrer les Conteneurs

```bash
docker compose up -d
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

Par défaut, MariaDB est accessible sur le port `3306` (configurable via `MARIADB_PORT` dans `.env`).

Connexion depuis un client MySQL :

```bash
mysql -h 127.0.0.1 -P 3306 -u mon_user -p mon_app
```

## Volumes Docker

Les volumes Docker sont utilisés pour :

- **Code source** : `./www` monté dans `/var/www/html`
- **Configuration Apache** : `./apache/vhost.conf` monté dans `/etc/apache2/sites-available/000-default.conf`
- **Configuration PHP** : `./php.ini` monté dans `/usr/local/etc/php/conf.d/custom.ini`
- **Données MariaDB** : Volume nommé `mariadb_data` pour la persistance

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

Éditez `apache/vhost.conf` et redémarrez le conteneur :

```bash
docker compose restart apache_app
```

### Modifier la Configuration PHP

Éditez `php.ini` et redémarrez le conteneur :

```bash
docker compose restart apache_app
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

