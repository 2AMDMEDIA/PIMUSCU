# PIM Musculation

Hub SaaS PrestaShop : synchronisation, scoring SEO et optimisation IA des contenus produits, catégories et champs custom.

Version simplifiée mono-CMS de [2AMD Media Hub](https://github.com/2AMDMEDIA/2amd-media-hub) (Next.js + Supabase), portée en **PHP/MySQL** pour déploiement sur hébergement mutualisé classique (OVH, Hostinger, o2switch...).

## Fonctionnalités MVP

- **Authentification** : login, mot de passe oublié, invitation, primo-connexion (PHP natif, bcrypt, sessions).
- **Multi-clients** : super-admin gère plusieurs boutiques PrestaShop, impersonation, quotas tokens IA.
- **Dashboard** : KPI produits, blog, pages CMS, usage IA + graphiques descriptions.
- **Catégories** : liste hiérarchique + détail (Version actuelle / Version optimisée par IA + push direct PrestaShop).
- **Produits** : grille de cartes + détail + génération texte + génération image secondaire (Kie.AI) + push direct.
- **Settings (5 onglets)** : config PrestaShop, compte, utilisateurs, outils IA (5 providers), ligne éditoriale.
- **Champs custom Presta** : auto-détection via Webservice + prompt IA personnalisé par champ.
- **Polling AJAX** pour les opérations longues (sync, génération).

## Stack

| Composant | Technologie |
|-----------|-------------|
| Langage | PHP 8.2+ |
| Base de données | MySQL 5.7+ / MariaDB 10.3+ (`utf8mb4_unicode_ci`) |
| Dépendances | `vlucas/phpdotenv`, `ramsey/uuid`, `phpmailer/phpmailer` |
| HTTP client | cURL natif |
| DB client | PDO natif |
| Templates | PHP plein (pas de moteur) |
| Routing | Front controller `public/index.php` + `config/routes.php` |
| Auth | Sessions PHP + bcrypt (`password_hash` cost 12) |
| Sync long | Polling AJAX (1.5s) |

## Installation

### Prérequis

- PHP 8.2 ou supérieur avec extensions : `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json`
- MySQL 5.7+ ou MariaDB 10.3+
- Composer (en dev local) ou les vendors uploadés via FTP (en prod mutualisé)
- Compte SMTP pour l'envoi des emails (invitations, reset)

### Étapes

```bash
# 1. Cloner le repo
git clone https://github.com/2AMDMEDIA/PIMUSCU.git
cd PIMUSCU

# 2. Installer les dépendances
composer install --no-dev --optimize-autoloader

# 3. Configurer l'environnement
cp .env.example .env
# Éditer .env avec vos identifiants DB, SMTP, et générer APP_SECRET :
php -r "echo bin2hex(random_bytes(32));"

# 4. Créer la base de données puis charger le schéma
mysql -u <user> -p <db_name> < migrations/001_init.sql

# 5. Créer le premier super-admin
php scripts/seed.php

# 6. Configurer le serveur web pour pointer vers public/
```

### Déploiement sur mutualisé (Amen / OVH / Hostinger / o2switch)

Le déploiement sur **Amen** est automatisé via GitHub Actions (`.github/workflows/deploy.yml`) : à chaque push sur `main`, le projet est uploadé en FTPS. Voir [DEPLOY.md](DEPLOY.md) pour le setup initial complet (secrets GitHub, sous-domaine, `.env` de prod, migrations SQL, premier super-admin).

Pour les autres hébergeurs (déploiement manuel) :
1. Uploader tout le projet **sauf** `vendor/` et `.env` via FTP.
2. Exécuter `composer install --no-dev` localement et uploader le dossier `vendor/` généré.
3. Créer `.env` directement sur le serveur avec les bonnes valeurs.
4. Importer `migrations/*.sql` via phpMyAdmin (dans l'ordre).
5. Pointer le domaine ou sous-domaine sur le dossier `public/` (via le panel de l'hébergeur).
6. Vérifier que `mod_rewrite` est activé (généralement par défaut).

## Arborescence

```
PIMUSCU/
├── public/                 # Seul dossier exposé via HTTP
│   ├── index.php           # Front controller
│   ├── .htaccess           # URL rewriting
│   └── assets/             # CSS, JS, images
├── src/
│   ├── Controllers/        # Logique des routes
│   ├── Services/           # Métier (PrestaShopClient, AIProvider, SeoScorer)
│   ├── Repositories/       # Accès DB
│   ├── Models/             # Objets métier
│   ├── Middleware/         # Auth, super-admin, client resolver
│   ├── Templates/          # Vues PHP
│   └── Helpers/            # Utilitaires
├── config/                 # Routes, DB, app
├── migrations/             # Fichiers SQL versionnés
├── storage/                # Logs, uploads (gitignored)
├── scripts/                # Scripts maintenance (seed, cleanup)
└── vendor/                 # Composer (gitignored)
```

## Configuration PrestaShop

Pour chaque boutique cliente, le super-admin renseigne :

1. **URL boutique** (ex. `https://maboutique.com`)
2. **Clé Webservice** : créée dans PrestaShop Admin → Paramètres avancés → Webservice. Activer les ressources `categories`, `products`, `image_types`, `images`.
3. (Optionnel) **Clé Blog Avancé** si le module est installé.

Pour exposer les champs custom (override d'objet `Category` ou `Product`), définir `webserviceParameters` dans la classe override pour rendre les champs accessibles via Webservice.

## Licence

Propriétaire — 2AMD Media. Tous droits réservés.
