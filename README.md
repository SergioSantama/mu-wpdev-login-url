# mu-wpdev-login-url

Mu-plugin del multisite **wpdev** que mueve el login de `/wp-login.php` a `/acceso-admin`
para reducir la superficie de fuerza bruta. **v2.0.0: se aplica a toda la red** — el host
principal, los subsitios por path y **todos los dominios mapeados** (hoy
`bilbaosoftware.es`, `ite-bilbao.es`, `chiptuningbilbao.es`).

## Qué hace

- Sirve el formulario de login en `https://wpdev.sergiosantamaria.org/acceso-admin`
  (también en el path de cada subsitio: `/chb/acceso-admin`) y en
  `https://<dominio-mapeado>/acceso-admin` de cada subsitio con dominio propio.
- `/wp-login.php` responde **404** para el formulario normal (`action` vacío o `action=login`).
- `/wp-admin` sin sesión redirige al login nuevo del MISMO host (filtro `login_url`).
- **Alcance en runtime, nada hardcodeado:** los hosts válidos se leen de `wp_blogs.domain` y
  de `{base_prefix}wu_domain_mappings` (Ultimate Multisite, si existe) en cada petición de
  login. Un dominio mapeado nuevo entra solo. Escape operativo:
  filtro `wpdev_login_scope_hosts`.

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
- **Rendimiento**: la comprobación de path va antes que cualquier consulta; solo las
  peticiones a `/acceso-admin` y `/wp-login.php` tocan la BD.

## ⚠ Cambio para los clientes (v2.0.0)

Los clientes que hoy entran a `bilbaosoftware.es/wp-admin` (o ite / chb) pasan a ser enviados a
`https://<su-dominio>/acceso-admin` y `/wp-login.php` les responde 404. Es el objetivo
(anti-brute-force) pero **cambia la URL de acceso** que tienen guardada: conviene avisarles
antes o al primer soporte.

## Verificación

```text
# en los 4 hosts (wpdev + 3 dominios mapeados)
/wp-login.php                      -> 404
/acceso-admin                      -> 200 (formulario)
/wp-admin/ (sin sesión)            -> 302 /acceso-admin?redirect_to=...
POST /acceso-admin (credenciales)  -> 302 /wp-admin/ ; GET /wp-admin/ -> 200 Dashboard
# además en wpdev
/chb/wp-login.php                  -> 404 ; /chb/acceso-admin -> 200
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
`/home/ubuntu/wpdev-mu-backups/`) o revertir el commit. El login vuelve a `/wp-login.php` en
toda la red.

## Licencia

GPL-2.0-or-later.
