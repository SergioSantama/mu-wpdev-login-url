# mu-wpdev-login-url

Mu-plugin del multisite **wpdev** (`https://wpdev.sergiosantamaria.org`) que mueve el login
de `/wp-login.php` a `/acceso-admin` para reducir la superficie de fuerza bruta.

## Qué hace

- Sirve el formulario de login en `https://wpdev.sergiosantamaria.org/acceso-admin`
  (también en el path de cada subsitio en ese host: `/chb/acceso-admin`, etc.).
- `/wp-login.php` responde **404** para el formulario normal (`action` vacío o `action=login`).
- `/wp-admin` sin sesión redirige al login nuevo (filtro `login_url` de WordPress).
- Solo actúa cuando `HTTP_HOST` es `wpdev.sergiosantamaria.org`. Los dominios de cliente
  (`chiptuningbilbao.es`, `ite-bilbao.es`, `bilbaosoftware.es`) mantienen su login nativo.

## Excepciones deliberadas

- **Acciones nativas permitidas en `/wp-login.php`**: `logout`, `lostpassword`, `rp`,
  `resetpass`, `postpass`, `confirmaction`, `interim-login`, `switch_to`, `switch_back`.
  Sin ellas se romperían el cierre de sesión, el reset de contraseña por email, los posts
  protegidos y User Switching.
- **Peticiones internas** (`REMOTE_ADDR` = `127.0.0.1`, `::1` o `149.56.130.238`): se dejan
  pasar para que el checker de DigitalYa Guardian, que hace un GET a `/wp-login.php` desde el
  propio servidor, no genere un falso positivo. Un atacante externo no puede falsificar
  `REMOTE_ADDR`.

## No rompe

- **EMCP / mcp-adapter**: usan application passwords + REST (`/wp-json/...`), no `wp-login.php`.
- **wp-cron**: `/wp-cron.php` no pasa por el login.
- **fail2ban**: el formulario usa `wp_signon()`, que dispara `wp_login` / `wp_login_failed`;
  `mu-wpdev-guard` sigue escribiendo el JSONL y el jail `wpdev-login` sigue baneando.
- **Dominios de cliente**: fuera del host wpdev no se aplica nada.

## Verificación (12-11)

```text
/wp-login.php                      -> 404
/acceso-admin                      -> 200 (formulario)
/wp-admin/ (sin sesión)            -> 302 /acceso-admin?redirect_to=...
POST /acceso-admin (credenciales)  -> 302 /wp-admin/ ; GET /wp-admin/ -> 200 Dashboard
/chb/wp-login.php                  -> 404 ; /chb/acceso-admin -> 200
https://bilbaosoftware.es/wp-login.php -> 200 (sin cambios)
/wp-cron.php                       -> 200
/wp-json/mcp/elementor-mcp-server initialize con app-password -> 200
wp-login.php?action=lostpassword   -> 200
wp-login.php?action=logout         -> 403 (confirmación nativa de WP)
```

## Despliegue

Repo individual + mu-sync (`/home/ubuntu/wpdev-mu-sync.sh` en vps1, cron 04:47). Deploy key de
lectura por repo y alias SSH `github-mu-loginurl`.

## Rollback

Borrar `wp-content/mu-plugins/mu-wpdev-login-url.php` (backups en
`/home/ubuntu/wpdev-mu-backups/`) o revertir el commit. El login vuelve a `/wp-login.php`.

## Licencia

GPL-2.0-or-later.
