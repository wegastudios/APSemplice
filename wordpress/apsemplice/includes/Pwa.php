<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * App installabile (PWA): manifest, service worker (solo offline minimo e notifiche: le pagine con dati personali non si salvano mai),
 * icone e collegamento dei dispositivi alle notifiche. Spenta di default.
 *
 * Gli indirizzi (manifest, service worker, icone) sono serviti dalla radice del sito senza regole di riscrittura: il server li passa a WordPress
 * come qualsiasi indirizzo che non è un file.
 */
final class Pwa {

	const ROUTES = array( 'apse-manifest.webmanifest', 'apse-sw.js', 'apse-offline.html', 'apse-icon-192.png', 'apse-icon-512.png' );

	public static function enabled(): bool {
		return (bool) Settings::get( 'pwa_enabled' ) && Edition::has( 'pwa' );
	}

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_apse_push_subscribe', array( __CLASS__, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_apse_push_unsubscribe', array( __CLASS__, 'ajax_unsubscribe' ) );
	}

	// ---------- Indirizzi ----------

	private static function base_path(): string {
		$p = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return '' === $p ? '/' : trailingslashit( $p );
	}

	public static function url( string $route ): string {
		return home_url( '/' . $route );
	}

	/** Quale dei nostri indirizzi è stato richiesto? (null se nessuno) */
	public static function route_of( string $request_uri ): ?string {
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$base = self::base_path();
		if ( 0 !== strpos( $path, $base ) ) {
			return null;
		}
		$rest = substr( $path, strlen( $base ) );
		return in_array( $rest, self::ROUTES, true ) ? $rest : null;
	}

	public static function maybe_serve(): void {
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$route = self::route_of( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security
		if ( null === $route ) {
			return;
		}
		$r = self::response( $route );
		status_header( $r['status'] );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		foreach ( $r['headers'] as $k => $v ) {
			header( $k . ': ' . $v );
		}
		header( 'Content-Type: ' . $r['type'] );
		header( 'X-Content-Type-Options: nosniff' );
		echo $r['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- manifest, script e immagini del plugin
		exit;
	}

	/** @return array{status:int,type:string,body:string,headers:array} */
	public static function response( string $route ): array {
		$on = self::enabled();
		switch ( $route ) {
			case 'apse-manifest.webmanifest':
				return $on
					? array( 'status' => 200, 'type' => 'application/manifest+json; charset=utf-8', 'body' => (string) wp_json_encode( self::manifest(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'headers' => array( 'Cache-Control' => 'no-cache' ) )
					: array( 'status' => 404, 'type' => 'text/plain; charset=utf-8', 'body' => 'Non disponibile.', 'headers' => array() );
			case 'apse-sw.js':
				return array( 'status' => 200, 'type' => 'application/javascript; charset=utf-8', 'body' => $on ? self::service_worker() : self::removal_script(), 'headers' => array( 'Service-Worker-Allowed' => self::base_path(), 'Cache-Control' => 'no-cache' ) );
			case 'apse-offline.html':
				return array( 'status' => 200, 'type' => 'text/html; charset=utf-8', 'body' => self::offline_page(), 'headers' => array() );
			default:
				$size = 'apse-icon-512.png' === $route ? 512 : 192;
				$rgb  = Png::rgb( self::theme_color() );
				return array( 'status' => 200, 'type' => 'image/png', 'body' => Png::solid( $size, $size, $rgb[0], $rgb[1], $rgb[2] ), 'headers' => array( 'Cache-Control' => 'public, max-age=86400' ) );
		}
	}

	// ---------- Contenuti ----------

	public static function theme_color(): string {
		$c = Color::normalize( (string) Settings::get( 'accent_color' ) );
		return '' !== $c ? $c : '#2271b1';
	}

	public static function app_name(): string {
		$n = trim( (string) Settings::get( 'pwa_name' ) );
		if ( '' === $n ) {
			$n = trim( (string) Settings::get( 'association_name' ) );
		}
		return mb_substr( '' !== $n ? $n : (string) get_bloginfo( 'name' ), 0, 45 );
	}

	public static function short_name(): string {
		$n = trim( (string) Settings::get( 'pwa_short_name' ) );
		return mb_substr( '' !== $n ? $n : self::app_name(), 0, 12 );
	}

	/** Icona scelta, poi l'icona del sito, poi un quadrato del colore d'accento generato dal plugin. */
	public static function icon_url( int $size ): string {
		$id = (int) Settings::get( 'pwa_icon_id' );
		if ( $id > 0 ) {
			$u = wp_get_attachment_url( $id );
			if ( $u ) {
				return $u;
			}
		}
		$site = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( $size ) : '';
		return '' !== $site ? $site : self::url( 512 === $size ? 'apse-icon-512.png' : 'apse-icon-192.png' );
	}

	public static function manifest(): array {
		$id    = (int) Settings::get( 'pwa_icon_id' );
		$meta  = $id > 0 ? wp_get_attachment_metadata( $id ) : null;
		$w     = is_array( $meta ) && ! empty( $meta['width'] ) ? (int) $meta['width'] : 0;
		$h     = is_array( $meta ) && ! empty( $meta['height'] ) ? (int) $meta['height'] : 0;
		$icons = $w >= 192 && $h >= 192
			? array( array( 'src' => self::icon_url( 512 ), 'sizes' => $w . 'x' . $h, 'type' => 'image/png', 'purpose' => 'any' ) )
			: array(
				array( 'src' => self::icon_url( 192 ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::icon_url( 512 ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
			);
		return array(
			'name'             => self::app_name(),
			'short_name'       => self::short_name(),
			'description'      => 'Area soci di ' . self::app_name(),
			'lang'             => 'it',
			'start_url'        => add_query_arg( 'source', 'pwa', Gatekeeper::area_url() ),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => self::theme_color(),
			'icons'            => $icons,
		);
	}

	/** Script che toglie un service worker rimasto da quando l'app era accesa. */
	private static function removal_script(): string {
		return "self.addEventListener('install',function(){self.skipWaiting();});\nself.addEventListener('activate',function(e){e.waitUntil(self.registration.unregister().then(function(){return caches.keys();}).then(function(k){return Promise.all(k.filter(function(n){return n.indexOf('apse-pwa-')===0;}).map(function(n){return caches.delete(n);}));}));});\n";
	}

	public static function service_worker(): string {
		$js = <<<'JS'
/* APSemplice — service worker. Le pagine non si salvano mai: i dati personali restano solo in rete. Qui solo offline minimo e notifiche. */
const CACHE = 'apse-pwa-%VER%';
const OFFLINE = '%OFFLINE%';
const ASSETS = '%ASSETS%';
const AREA = '%AREA%';
const NAME = %NAME%;
const ICON = '%ICON%';
self.addEventListener('install', function (e) {
	e.waitUntil(caches.open(CACHE).then(function (c) { return c.add(new Request(OFFLINE, { cache: 'reload' })); }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (e) {
	e.waitUntil(caches.keys().then(function (keys) {
		return Promise.all(keys.filter(function (k) { return k !== CACHE && k.indexOf('apse-pwa-') === 0; }).map(function (k) { return caches.delete(k); }));
	}).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (e) {
	var req = e.request;
	if (req.method !== 'GET') { return; }
	var url = new URL(req.url);
	if (req.mode === 'navigate') {
		e.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE); }));
		return;
	}
	if (url.origin === self.location.origin && url.pathname.indexOf(ASSETS) === 0) {
		e.respondWith(caches.open(CACHE).then(function (c) {
			return c.match(req).then(function (hit) {
				var net = fetch(req).then(function (r) { if (r && r.ok) { c.put(req, r.clone()); } return r; }).catch(function () { return hit; });
				return hit || net;
			});
		}));
	}
});
self.addEventListener('push', function (e) {
	var d = {};
	try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
	e.waitUntil(self.registration.showNotification(d.title || NAME, { body: d.body || '', icon: d.icon || ICON, badge: d.icon || ICON, data: { url: d.url || AREA } }));
});
self.addEventListener('notificationclick', function (e) {
	e.notification.close();
	var url = AREA;
	try {
		var u = new URL((e.notification.data && e.notification.data.url) || AREA, self.location.origin);
		if (u.origin === self.location.origin) { url = u.href; }
	} catch (err) {}
	e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
		for (var i = 0; i < list.length; i++) { if (list[i].url === url && 'focus' in list[i]) { return list[i].focus(); } }
		return self.clients.openWindow(url);
	}));
});
JS;
		return strtr(
			$js,
			array(
				'%VER%'     => APSE_VERSION . '-' . substr( md5( self::theme_color() . self::app_name() ), 0, 6 ),
				'%OFFLINE%' => self::url( 'apse-offline.html' ),
				'%ASSETS%'  => (string) wp_parse_url( APSE_URL . 'assets/', PHP_URL_PATH ),
				'%AREA%'    => esc_url_raw( Gatekeeper::area_url() ),
				'%NAME%'    => (string) wp_json_encode( self::app_name(), JSON_UNESCAPED_UNICODE ),
				'%ICON%'    => esc_url_raw( self::icon_url( 192 ) ),
			)
		);
	}

	public static function offline_page(): string {
		$name = esc_html( self::app_name() );
		$col  = esc_attr( self::theme_color() );
		return '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sei offline</title>'
			. '<style>body{font-family:system-ui,sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px;color:#1d2327}'
			. 'h1{font-size:1.4rem;margin:.2em 0}p{color:#50575e}.dot{width:56px;height:56px;border-radius:50%;background:' . $col . ';margin:0 auto 16px}</style></head>'
			. '<body><main><div class="dot"></div><h1>Sei offline</h1><p>' . $name . ' ha bisogno di una connessione per mostrarti i tuoi dati. Riprova appena sei di nuovo online.</p></main></body></html>';
	}

	// ---------- Pagine del sito ----------

	public static function print_head(): void {
		if ( ! self::enabled() || is_admin() ) {
			return;
		}
		echo '<link rel="manifest" href="' . esc_url( self::url( 'apse-manifest.webmanifest' ) ) . '">' . "\n"
			. '<meta name="theme-color" content="' . esc_attr( self::theme_color() ) . '">' . "\n"
			. '<meta name="mobile-web-app-capable" content="yes">' . "\n"
			. '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
			. '<meta name="apple-mobile-web-app-title" content="' . esc_attr( self::short_name() ) . '">' . "\n"
			. '<link rel="apple-touch-icon" href="' . esc_url( self::icon_url( 192 ) ) . '">' . "\n";
	}

	public static function enqueue(): void {
		if ( ! self::enabled() || is_admin() ) {
			return;
		}
		wp_enqueue_script( 'apse-pwa', APSE_URL . 'assets/pwa.js', array(), Plugin::asset_version( 'pwa.js' ), true );
		wp_localize_script(
			'apse-pwa',
			'APSE_PWA',
			array(
				'sw'    => self::url( 'apse-sw.js' ),
				'scope' => home_url( '/' ),
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => is_user_logged_in() ? wp_create_nonce( 'apse_push' ) : '',
				'push'  => Push::enabled() && is_user_logged_in(),
				'vapid' => Push::enabled() && is_user_logged_in() ? Push::public_key() : '',
			)
		);
	}

	// ---------- Dispositivi ----------

	private static function ajax_guard(): int {
		check_ajax_referer( 'apse_push', 'nonce' );
		if ( ! is_user_logged_in() || ! Push::enabled() ) {
			wp_send_json_error( array( 'message' => 'Le notifiche non sono attive.' ), 403 );
		}
		return get_current_user_id();
	}

	public static function ajax_subscribe(): void {
		$uid = self::ajax_guard();
		try {
			Push::subscribe(
				$uid,
				array( 'endpoint' => (string) wp_unslash( $_POST['endpoint'] ?? '' ), 'keys' => array( 'p256dh' => (string) wp_unslash( $_POST['p256dh'] ?? '' ), 'auth' => (string) wp_unslash( $_POST['auth'] ?? '' ) ) ), // phpcs:ignore WordPress.Security
				isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''
			);
		} catch ( \InvalidArgumentException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}
		wp_send_json_success( array( 'subscribed' => true ) );
	}

	public static function ajax_unsubscribe(): void {
		$uid = self::ajax_guard();
		Push::unsubscribe( $uid, (string) wp_unslash( $_POST['endpoint'] ?? '' ) ); // phpcs:ignore WordPress.Security
		wp_send_json_success( array( 'subscribed' => false ) );
	}
}
