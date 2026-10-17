<?php
// Copia in config.php (fuori dalla cartella pubblica se possibile) e compila. Mai nel repository.
return array(
	'base_url' => 'https://portale.associazionesemplice.it',
	'app_key'  => '',   // 32 byte in base64: php -r "echo base64_encode(random_bytes(32));"  (cifra le chiavi di licenza)
	'mail_from' => 'info@associazionesemplice.it',
	'indexable' => false, // finché è false: robots.txt, X-Robots-Tag e meta robots vietano l'indicizzazione (cambiare anche public/robots.txt prima del lancio)
	'trust_forwarded_for' => false, // true solo dietro un proxy fidato: i limiti del rimbalzo licenze usano X-Forwarded-For invece dell'IP della connessione
	'admins'    => array(),   // email che possono entrare nel pannello di gestione (stesso accesso dei clienti, link per email)

	// MySQL oppure SQLite (default).
	'db' => array(
		'dsn'  => 'sqlite:' . __DIR__ . '/data/portale.sqlite',
		// 'dsn' => 'mysql:host=localhost;dbname=portale;charset=utf8mb4',
		'user' => '',
		'pass' => '',
	),

	// Server licenze Wega (token con scope licenses:create, licenses:read, licenses:change-binding).
	'wega' => array(
		'base_url' => 'https://license.wegastudios.com/api/v1',
		'token'    => '',   // wic_xxxxxxxx.SEGRETO
	'fallback_ip' => '65.108.75.89', // usato solo se il DNS non risolve license.wegastudios.com (vuoto = mai)
	),

	'stripe' => array(
		'secret_key'     => '',
		'webhook_secret' => '',
	),
	'paypal' => array(
		'live'       => false,
		'client_id'  => '',
		'secret'     => '',
		'webhook_id' => '',
	),

	// Giorni di margine tra pagamento e scadenza della licenza (la tolleranza del server è 5 giorni).
	'duration_days' => array( 'month' => 31, 'year' => 366, 'free' => 3650 ),
	// Un pagamento PayPal è trattato come rinnovo solo negli ultimi N giorni di validità (evita il doppio conteggio del primo).
	'renew_window_days' => 5,

	// Piani: plan_name è il nome inviato al server licenze. I prezzi sono in centesimi (solo per la vetrina:
	// l'importo realmente addebitato è quello del prezzo/piano creato su Stripe e PayPal).
	'plans' => array(
		'free' => array( 'label' => 'Free', 'plan_name' => 'Free', 'prices' => array() ),
		'lite' => array(
			'label' => 'Pro Lite', 'plan_name' => 'Pro Lite',
			'description' => 'Tutto il Pro, senza la parte contabile.',
			'prices' => array(
				'month' => array( 'cents' => 500,  'stripe_price' => '', 'paypal_plan' => '' ),
				'year'  => array( 'cents' => 4800, 'stripe_price' => '', 'paypal_plan' => '' ),
			),
		),
		'full' => array(
			'label' => 'Pro Full', 'plan_name' => 'Pro Full',
			'description' => 'Tutto: contabilità, IVA, 5 per mille, rendiconto.',
			'prices' => array(
				'month' => array( 'cents' => 1200, 'stripe_price' => '', 'paypal_plan' => '' ),
				'year'  => array( 'cents' => 11900, 'stripe_price' => '', 'paypal_plan' => '' ),
			),
		),
	),
);
