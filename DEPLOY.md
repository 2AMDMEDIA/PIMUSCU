# Déploiement sur Amen (FTPS)

Le projet se déploie automatiquement sur ton hébergement Amen à chaque push sur `main` via GitHub Actions (`.github/workflows/deploy.yml`).

## Setup initial (à faire UNE seule fois)

### 1. Ajouter les secrets GitHub

Va sur https://github.com/2AMDMEDIA/PIMUSCU/settings/secrets/actions et ajoute 3 secrets :

| Nom | Valeur |
|---|---|
| `FTP_HOST` | Le serveur FTP Amen (ex. `ftp.tondomaine.com` ou l'IP fournie par Amen) |
| `FTP_USERNAME` | Ton identifiant FTP Amen |
| `FTP_PASSWORD` | Ton mot de passe FTP Amen |

> Le port `21` est hardcodé dans le workflow (FTP standard). Si Amen utilise un port différent, modifie `port:` dans `.github/workflows/deploy.yml`.

### 2. Créer le sous-domaine sur le panel Amen

1. Connecte-toi au manager Amen
2. Crée un sous-domaine (ex. `hub.tondomaine.com`)
3. Configure son **document root** sur **`/public/`**
   - Si l'option n'existe pas dans le panel Amen, contacte le support pour qu'ils pointent le sous-domaine sur ce dossier (ou mets-toi sur l'option B — voir plus bas)

### 3. Créer la base de données MySQL chez Amen

1. Dans le manager Amen, crée une base MySQL (ex. `pim_musculation`)
2. Note bien : nom de la base, utilisateur, mot de passe, hôte
3. Tu importeras le schéma à l'étape 5

### 4. Créer le `.env` sur le serveur (manuellement via FTP/SFTP)

⚠️ La GitHub Action **ne touche jamais** le `.env` sur le serveur (sinon elle écraserait ta config prod à chaque déploiement).

À uploader manuellement dans `/.env` (à côté de `composer.json`, **PAS** dans `public/`) :

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://hub.tondomaine.com
APP_NAME="PIM Musculation"

# Base MySQL (les valeurs Amen)
DB_HOST=mysql.amen.fr      # ou ce qu'Amen te fournit
DB_PORT=3306
DB_NAME=pim_musculation
DB_USER=ton_user
DB_PASS=ton_password
DB_CHARSET=utf8mb4

# SMTP (à configurer pour les emails d'invitation / reset)
MAIL_HOST=smtp.amen.fr
MAIL_PORT=587
MAIL_USER=
MAIL_PASS=
MAIL_ENCRYPTION=tls
MAIL_FROM_EMAIL=noreply@tondomaine.com
MAIL_FROM_NAME="PIM Musculation"

# Clé AES — gère-la PRÉCIEUSEMENT (si perdue, toutes les API keys chiffrées en DB deviennent illisibles)
# Générer avec : php -r "echo bin2hex(random_bytes(32));"
APP_SECRET=METS_UNE_VALEUR_GENEREE_ICI

SESSION_NAME=pim_musculation_sess
SESSION_LIFETIME=86400

# IMPORTANT : true en prod (false uniquement en dev local derrière un antivirus type Avast)
APP_TLS_VERIFY=true
```

### 5. Lancer l'installation via /install (recommandé)

1. Ajoute dans ton `.env` (à la racine FTP) un `INSTALL_TOKEN` aléatoire :
   ```env
   INSTALL_TOKEN=ton_token_secret_long_et_unique
   ```
   Pour en générer un : `php -r "echo bin2hex(random_bytes(16));"`

2. Va sur `https://hub.tondomaine.com/install?token=ton_token_secret_long_et_unique`

3. La page affiche :
   - ✓ ou ✕ connexion DB
   - Liste des migrations à appliquer
   - Formulaire de création du super-admin

4. Remplis le formulaire (email + mot de passe + nom) et clique "Lancer l'installation" :
   - Applique les migrations dans l'ordre (1 puis 2 puis 3)
   - Crée ton compte super-admin
   - **Verrouille définitivement** l'installateur via `storage/install.lock`

5. Tu peux ensuite te connecter sur `/login` avec les identifiants saisis.

> Pour les futures migrations (à venir après ce déploiement), utilise plutôt la page `/admin/migrations` une fois connecté.
>
> **Ne lance JAMAIS `scripts/migrate.php` sur prod** — il fait des `DROP TABLE`.

### 6. Si /install ne fonctionne pas (fallback manuel)

Si pour une raison X /install n'est pas accessible, tu peux toujours :
- Importer manuellement les migrations via phpMyAdmin Amen
- Créer le super-admin via INSERT SQL avec un hash bcrypt généré localement :
  ```powershell
  php -r "echo password_hash('TonMotDePasse', PASSWORD_BCRYPT, ['cost' => 12]);"
  ```
  ```sql
  INSERT INTO users (id, email, password_hash, full_name, is_super_admin)
  VALUES (UUID(), 'toi@email.com', 'COLLE_LE_HASH_ICI', 'Ton Nom', 1);
  ```

### 7. Premier déploiement

À ce stade :
- ✅ Secrets GitHub configurés
- ✅ Sous-domaine pointant sur `/public/`
- ✅ Base MySQL créée + schéma chargé
- ✅ `.env` créé sur le serveur
- ✅ Super-admin créé en DB

Va sur https://github.com/2AMDMEDIA/PIMUSCU/actions et clique sur **"Run workflow"** sur la workflow "Deploy to Amen FTPS" pour déclencher le premier déploiement (ou pousse n'importe quel commit).

Le déploiement upload ~2-5 minutes la première fois (la suite, ~30s grâce au diff).

## Déploiements suivants

À chaque `git push origin main` → upload auto. Pas besoin de toucher au serveur.

## Ce qui est exclu de l'upload

La GitHub Action exclut systématiquement :
- `.env`, `.env.*` (sauf `.env.example`)
- `storage/logs/`, `storage/uploads/` (conservés sur le serveur entre déploiements)
- `.git/`, `.github/`, `.claude/` (config dev locale)
- `scripts/_*.php` (scripts de debug temporaires)
- `tests/`, `*.log`, `node_modules/`, etc.

Le dossier `vendor/` **est** uploadé (généré au runtime par `composer install --no-dev` dans l'Action).

## Si quelque chose plante au déploiement

1. Va sur https://github.com/2AMDMEDIA/PIMUSCU/actions
2. Clique sur le run rouge → tu verras le log complet (étape qui plante, message d'erreur)
3. Les erreurs typiques :
   - **530 Login incorrect** → vérifier `FTP_USERNAME` / `FTP_PASSWORD` dans les Secrets
   - **Connection timeout** → vérifier `FTP_HOST` et `FTP_PORT`
   - **TLS handshake failed** → port `990` au lieu de `21` ou inversement
   - **553 No such file or directory** → vérifier que la racine FTP est bien accessible en écriture
   - **Files non synchronisés** → supprimer `.ftp-deploy-sync-state.json` côté FTP et relancer
