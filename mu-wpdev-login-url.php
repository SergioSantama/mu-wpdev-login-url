<?php
/**
 * Plugin Name: WPDev Login URL
 * Description: Sirve el login del multisite wpdev en /acceso-admin (también en cada subsitio y en cada dominio mapeado de la red), oculta /wp-login.php (404) y redirige /wp-admin al login nuevo.
 * Version: 2.0.0
 * Author: DigitalYa
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
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	header( 'Referrer-Policy: no-referrer', true );

	$error       = '';
	$redirect_to = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo alimenta wp_validate_redirect.
	$redirect_to = wp_validate_redirect( $redirect_to, admin_url() );

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
				wp_safe_redirect( $redirect_to );
				exit;
			}
		}
	} elseif ( is_user_logged_in() ) {
		wp_safe_redirect( $redirect_to );
		exit;
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

	if ( WPDEV_LOGIN_SLUG !== $base && 'wp-login.php' !== $base ) {
		return;
	}

	if ( ! wpdev_login_is_scope() ) {
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
