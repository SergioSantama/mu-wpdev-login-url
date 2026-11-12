<?php
/**
 * Plugin Name: WPDev Login URL
 * Description: Sirve el login del multisite wpdev en /acceso-admin (también en cada subsitio y en cada dominio mapeado de la red), oculta /wp-login.php (404) y redirige /wp-admin al login nuevo.
 * Version: 2.2.0
 * Author: Sergio Santamaria
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WPDev_Login_URL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Host principal de la red (siempre en el alcance, aunque wp_blogs no esté disponible).
 */
const WPDEV_LOGIN_HOST = 'wpdev.sergiosantamaria.org';

/**
 * Slug público del login.
 */
const WPDEV_LOGIN_SLUG = 'acceso-admin';

/**
 * Normaliza un host: minúsculas, sin puerto y sin punto final.
 *
 * @param string $host Host en bruto (p. ej. de HTTP_HOST o de wp_blogs.domain).
 * @return string
 */
function wpdev_login_normalize_host( string $host ): string {
	$host = strtolower( trim( $host ) );
	$host = (string) preg_replace( '/:\d+$/', '', $host );

	return rtrim( $host, '.' );
}

/**
 * Hosts donde se aplica el cambio de login: el host de la red + TODOS los
 * dominios de los subsitios (incluidos los mapeados) leídos en runtime.
 *
 * Fuentes, en este orden:
 * 1. `wp_blogs.domain` (multisite nativo; un dominio mapeado es una fila más).
 * 2. `{base_prefix}wu_domain_mappings` (Ultimate Multisite, si la tabla existe).
 * 3. Filtro `wpdev_login_scope_hosts` (escape operativo sin tocar el fichero).
 *
 * Nada está hardcodeado: un dominio mapeado nuevo entra solo.
 *
 * @return array<int,string>
 */
function wpdev_login_scope_hosts(): array {
	static $hosts = null;

	if ( null !== $hosts ) {
		return $hosts;
	}

	$hosts = array( WPDEV_LOGIN_HOST );

	global $wpdb;

	if ( is_object( $wpdb ) && ! empty( $wpdb->blogs ) ) {
		$domains = $wpdb->get_col( "SELECT DISTINCT domain FROM {$wpdb->blogs}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->blogs es una propiedad de core.

		if ( is_array( $domains ) ) {
			foreach ( $domains as $domain ) {
				$hosts[] = wpdev_login_normalize_host( (string) $domain );
			}
		}

		/*
		 * Dominios extra de Ultimate Multisite (dominio propio por site). La
		 * tabla puede no existir si UM no está instalado o usa otro prefijo.
		 */
		$table = $wpdb->base_prefix . 'wu_domain_mappings';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Una consulta por petición de login.

		if ( $exists ) {
			$um_domains = $wpdb->get_col( "SELECT DISTINCT domain FROM {$table} WHERE active = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla construido con el prefijo de core.

			if ( is_array( $um_domains ) ) {
				foreach ( $um_domains as $domain ) {
					$hosts[] = wpdev_login_normalize_host( (string) $domain );
				}
			}
		}
	}

	$hosts = array_values( array_unique( array_filter( $hosts ) ) );

	/**
	 * Permite añadir o quitar hosts del alcance sin editar el plugin.
	 *
	 * @param array<int,string> $hosts Hosts normalizados.
	 */
	$hosts = (array) apply_filters( 'wpdev_login_scope_hosts', $hosts );

	return $hosts;
}

/**
 * ¿La petición actual va a un host de la red (principal o dominio mapeado)?
 *
 * @return bool
 */
function wpdev_login_is_scope(): bool {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? wpdev_login_normalize_host( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

	return in_array( $host, wpdev_login_scope_hosts(), true );
}

/**
 * Path normalizado de la petición actual (sin barra final).
 *
 * @return string
 */
function wpdev_login_request_path(): string {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

	return rtrim( strtolower( $path ), '/' );
}

/**
 * ¿La petición viene del propio servidor (health checks / cron)?
 *
 * El checker de DigitalYa Guardian hace un GET a /wp-login.php desde el
 * servidor; se le deja pasar para no convertirlo en falso positivo de
 * monitorización. Un atacante externo no puede falsificar REMOTE_ADDR.
 *
 * @return bool
 */
function wpdev_login_is_internal_request(): bool {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

	return in_array( $ip, array( '127.0.0.1', '::1', '149.56.130.238' ), true );
}

/**
 * Acciones de /wp-login.php que SIGUEN permitidas (logout, reset de
 * contraseña, postpass, confirmación de email, interim-login y User
 * Switching). El formulario de login normal queda bloqueado.
 *
 * @return array<int,string>
 */
function wpdev_login_allowed_actions(): array {
	return array(
		'logout',
		'lostpassword',
		'rp',
		'resetpass',
		'postpass',
		'confirmaction',
		'interim-login',
		'switch_to',
		'switch_back',
	);
}

/**
 * Host (normalizado) de la petición actual.
 *
 * @return string
 */
function wpdev_login_current_host(): string {
	return isset( $_SERVER['HTTP_HOST'] ) ? wpdev_login_normalize_host( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
}

/**
 * admin_url() del blog actual, garantizando que apunta al host actual.
 *
 * WordPress 7.1 cambió `wp_validate_redirect()`: ya NO devuelve el fallback
 * cuando la URL de entrada es una cadena vacía (devuelve ''). Por eso el
 * fallback se calcula aquí y se aplica siempre que haga falta.
 *
 * @return string
 */
function wpdev_login_current_admin_url(): string {
	$admin = (string) admin_url();

	if ( '' !== $admin ) {
		return $admin;
	}

	$host = wpdev_login_current_host();

	if ( '' === $host ) {
		return '';
	}

	$blog_path = '/';

	if ( function_exists( 'get_blog_details' ) ) {
		$blog = get_blog_details();

		if ( is_object( $blog ) && ! empty( $blog->path ) ) {
			$blog_path = trailingslashit( (string) $blog->path );
		}
	}

	return ( is_ssl() ? 'https' : 'http' ) . '://' . $host . $blog_path . 'wp-admin/';
}

/**
 * Redirige SIEMPRE al dashboard del blog actual en SU dominio y nunca deja
 * una pantalla en blanco:
 *
 * 1. Valida el destino y, si viene vacío o apunta a otro host de la red,
 *    usa el admin_url() del blog actual.
 * 2. Autoriza el host en `allowed_redirect_hosts` (red multisite) para que
 *    wp_safe_redirect() no lo rechace.
 * 3. Si wp_safe_redirect() falla (p. ej. output previo de otro plugin), cae
 *    a wp_redirect() con el destino ya validado y, si tampoco, pinta una
 *    página de continuación con enlace + meta refresh.
 *
 * @param string $target   Destino solicitado (puede venir vacío).
 * @param int    $ob_level Nivel de output buffering previo al nuestro.
 * @return void
 */
function wpdev_login_redirect( string $target, int $ob_level = 0 ): void {
	$fallback = wpdev_login_current_admin_url();

	$target = (string) wp_validate_redirect( $target, $fallback );

	if ( '' === $target ) {
		$target = $fallback;
	}

	$current_host = wpdev_login_current_host();
	$target_host  = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );

	/*
	 * Un redirect_to de otro host de la red abriría el dashboard del blog
	 * equivocado; el login siempre deja al usuario en el blog actual.
	 */
	if ( '' === $target_host || $target_host !== $current_host ) {
		$target      = $fallback;
		$target_host = $current_host;
	}

	// Descarta cualquier output que un plugin haya emitido durante wp_signon().
	while ( ob_get_level() > $ob_level ) {
		ob_end_clean();
	}

	$allow_host = static function ( $hosts ) use ( $target_host ) {
		$hosts   = (array) $hosts;
		$hosts[] = $target_host;

		return array_values( array_unique( $hosts ) );
	};

	add_filter( 'allowed_redirect_hosts', $allow_host );
	$redirected = wp_safe_redirect( $target );
	remove_filter( 'allowed_redirect_hosts', $allow_host );

	if ( ! $redirected && in_array( $target_host, wpdev_login_scope_hosts(), true ) ) {
		$redirected = wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Destino validado contra los hosts de la red.
	}

	if ( ! $redirected ) {
		// Último recurso: nunca pantalla en blanco.
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . esc_url( $target ) . '"><meta name="robots" content="noindex,nofollow"><title>Acceso</title></head><body><p>Acceso correcto. <a href="' . esc_url( $target ) . '">Continuar al panel</a>.</p></body></html>';
	}

	exit;
}

/**
 * Sustituye /wp-login.php por /acceso-admin en las URLs de login.
 *
 * @param string $login_url    URL de login generada por WordPress.
 * @param string $redirect     Destino opcional.
 * @param bool   $force_reauth Fuerza reautenticación.
 * @return string
 */
function wpdev_login_filter_login_url( $login_url, $redirect = '', $force_reauth = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	if ( ! wpdev_login_is_scope() ) {
		return $login_url;
	}

	return str_replace( 'wp-login.php', WPDEV_LOGIN_SLUG, (string) $login_url );
}
add_filter( 'login_url', 'wpdev_login_filter_login_url', 10, 3 );

/**
 * Pinta el formulario de login y procesa el POST.
 *
 * @return void
 */
function wpdev_login_render(): void {
	/*
	 * Buffer propio: si un plugin escribe durante wp_signon(), se descarta
	 * antes de redirigir para no romper la cabecera Location (pantalla blanca).
	 */
	$ob_level = ob_get_level();
	ob_start();

	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	header( 'Referrer-Policy: no-referrer', true );

	$fallback    = wpdev_login_current_admin_url();
	$error       = '';
	$redirect_to = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo alimenta wp_validate_redirect.
	$redirect_to = (string) wp_validate_redirect( $redirect_to, $fallback );

	if ( '' === $redirect_to ) {
		$redirect_to = $fallback;
	}

	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
		$nonce = isset( $_POST['_wpdev_login_nonce'] ) ? (string) $_POST['_wpdev_login_nonce'] : '';

		if ( ! wp_verify_nonce( $nonce, 'wpdev_login' ) ) {
			$error = 'La sesión ha caducado. Recarga la página e inténtalo de nuevo.';
		} else {
			$user_login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
			$user_pass  = isset( $_POST['pwd'] ) ? (string) $_POST['pwd'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- La contraseña no se sanea, la valida wp_signon.

			$user = wp_signon(
				array(
					'user_login'    => $user_login,
					'user_password' => $user_pass,
					'remember'      => ! empty( $_POST['rememberme'] ),
				),
				is_ssl()
			);

			if ( is_wp_error( $user ) ) {
				$error = 'Usuario o contraseña incorrectos.';
			} else {
				wpdev_login_redirect( $redirect_to, $ob_level );
			}
		}
	} elseif ( is_user_logged_in() ) {
		wpdev_login_redirect( $redirect_to, $ob_level );
	}

	status_header( 200 );

	$request_path = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
	$dir          = rtrim( dirname( $request_path ), '/' );
	$lost_url     = $dir . '/wp-login.php?action=lostpassword';
	?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Acceso</title>
	<style>
		*{box-sizing:border-box}
		body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327}
		.card{background:#fff;border:1px solid #dcdcde;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.04);width:100%;max-width:360px;padding:32px 28px}
		h1{font-size:20px;line-height:1.3;margin:0 0 20px;text-align:center;font-weight:600}
		label{display:block;font-size:13px;font-weight:600;margin:0 0 6px}
		input[type=text],input[type=password]{width:100%;padding:9px 11px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;margin:0 0 16px;background:#fff}
		input[type=text]:focus,input[type=password]:focus{outline:2px solid #2271b1;outline-offset:0;border-color:#2271b1}
		.row{display:flex;align-items:center;gap:8px;margin:0 0 18px;font-size:13px}
		button{width:100%;padding:10px 14px;border:0;border-radius:4px;background:#2271b1;color:#fff;font-size:14px;font-weight:600;cursor:pointer}
		button:hover{background:#135e96}
		.err{background:#fcf0f1;border-left:4px solid #d63638;padding:10px 12px;font-size:13px;margin:0 0 18px;border-radius:2px}
		.links{margin:18px 0 0;text-align:center;font-size:12px}
		.links a{color:#2271b1;text-decoration:none}
		.links a:hover{text-decoration:underline}
	</style>
</head>
<body>
	<main class="card">
		<h1>Acceso</h1>
		<?php if ( '' !== $error ) : ?>
			<div class="err" role="alert"><?php echo esc_html( $error ); ?></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $request_path ); ?>">
			<label for="user_login">Usuario o email</label>
			<input type="text" name="log" id="user_login" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false" required>
			<label for="user_pass">Contraseña</label>
			<input type="password" name="pwd" id="user_pass" autocomplete="current-password" spellcheck="false" required>
			<div class="row">
				<input type="checkbox" name="rememberme" id="rememberme" value="forever">
				<label for="rememberme" style="margin:0;font-weight:400">Recordarme</label>
			</div>
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
			<?php wp_nonce_field( 'wpdev_login', '_wpdev_login_nonce' ); ?>
			<button type="submit">Entrar</button>
		</form>
		<p class="links"><a href="<?php echo esc_url( $lost_url ); ?>">¿Has olvidado tu contraseña?</a></p>
	</main>
</body>
</html>
	<?php
	exit;
}

/**
 * Intercepta /acceso-admin y /wp-login.php antes de que WordPress resuelva
 * la query (init corre también en wp-login.php porque éste carga wp-load).
 *
 * La comprobación de path va PRIMERO para no tocar la BD en el resto de
 * peticiones (frontend, REST, cron).
 *
 * @return void
 */
function wpdev_login_intercept(): void {
	$path = wpdev_login_request_path();
	$base = basename( $path );

	if ( WPDEV_LOGIN_SLUG !== $base && 'wp-login.php' !== $base && 'wp-admin' !== $base ) {
		return;
	}

	if ( ! wpdev_login_is_scope() ) {
		return;
	}

	/*
	 * /wp-admin (directorio) SIN sesión -> 404 (no revelar la URL del login a
	 * un atacante). CON sesión -> se deja a WordPress: el dashboard se sirve
	 * normalmente. Sin este return, el bloque 404 final también mataba
	 * /wp-admin/ con sesión iniciada (bug de la v2.1.1).
	 */
	if ( 'wp-admin' === $base ) {
		$req    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$is_api = ( false !== strpos( $req, 'admin-ajax' ) || false !== strpos( $req, 'admin-post' ) );

		if ( wpdev_login_is_internal_request() || $is_api ) {
			return;
		}

		require_once ABSPATH . 'wp-includes/pluggable.php';

		if ( ! is_user_logged_in() ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8', true );
			header( 'X-Robots-Tag: noindex, nofollow', true );
			echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>';
			exit;
		}

		return;
	}

	if ( WPDEV_LOGIN_SLUG === $base ) {
		wpdev_login_render();
	}

	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo decide si se permite la acción nativa.

	if ( '' !== $action && in_array( $action, wpdev_login_allowed_actions(), true ) ) {
		return;
	}

	if ( wpdev_login_is_internal_request() ) {
		return;
	}

	status_header( 404 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8', true );
	header( 'X-Robots-Tag: noindex, nofollow', true );
	echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>';
	exit;
}
add_action( 'init', 'wpdev_login_intercept', 1 );
