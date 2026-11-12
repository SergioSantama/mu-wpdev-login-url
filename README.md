# mu-wpdev-login-url

Mu-plugin del multisite **wpdev** que mueve el login de `/wp-login.php` a `/acceso-admin`
para reducir la superficie de fuerza bruta. **v2.0.0: se aplica a toda la red** — el host
principal, los subsitios por path y **todos los dominios mapeados** (hoy
`bilbaosoftware.es`, `ite-bilbao.es`, `chiptuningbilbao.es`).

**v2.2.0 (2026-11-12):** corrige la pantalla en blanco tras el login y el 404 de
`/wp-admin/` con sesión iniciada (ver §Cambios v2.2.0).

## Qué hace

- Sirve el formulario de login en `https://wpdev.sergiosantamaria.org/acceso-admin`
  (también en el path de cada subsitio: `/chb/acceso-admin`) y en
  `https://<dominio-mapeado>/acceso-admin` de cada subsitio con dominio propio.
- `/wp-login.php` responde **404** para el formulario normal (`action` vacío o `action=login`).
- `/wp-admin` (directorio) sin sesión responde **404**; con sesión sirve el dashboard.
  Cualquier página interna (`/wp-admin/post.php`, …) sin sesión redirige al login nuevo del
  MISMO host (filtro `login_url`).
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

## Cambios v2.2.0 (2026-11-12)

Dos bugs reales, verificados en vivo:

1. **Pantalla en blanco tras el login correcto.** WordPress 7.1 cambió
   `wp_validate_redirect()`: para una URL de entrada vacía ya **no devuelve el fallback**,
   devuelve `''`. El formulario de `/acceso-admin` (sin `redirect_to`, que es el caso normal)
   acababa con `wp_safe_redirect('')` → sin `Location` → 200 con cuerpo vacío, aunque el
   usuario quedaba logueado (cookie puesta). Ahora el destino vacío se sustituye por
   `admin_url()` del blog actual y, si el `redirect_to` apunta a otro host de la red, también
   se fuerza el blog actual (login siempre en SU dominio).
2. **`/wp-admin/` con sesión → 404.** La v2.1.1 interceptaba `/wp-admin` para devolver 404
   sin sesión, pero con sesión seguía al bloque 404 final del fichero. Ahora, con sesión, se
   deja servir el dashboard normal de WordPress.

Además: buffer propio para descartar output de otros plugins antes de redirigir;
`allowed_redirect_hosts` con los hosts de la red; si `wp_safe_redirect()` falla cae a
`wp_redirect()` con destino ya validado y, como último recurso, pinta una página de
continuación (nunca pantalla blanca).

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
/wp-admin/ (sin sesión)            -> 404
/wp-admin/ (con sesión)            -> 200 Dashboard
/wp-admin/post.php (sin sesión)    -> 302 /acceso-admin?redirect_to=...
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
