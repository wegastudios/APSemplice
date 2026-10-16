<?php /** @var string $content */ ?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ( ! \Portale\Config::get( 'indexable', false ) ) : ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<title>Associazione Semplice · Portale</title>
<style>
:root { --bg:#fafaf7; --fg:#1d2433; --muted:#5b6475; --card:#fff; --line:#dfe3ea; --brand:#1f6f5c; --brand-fg:#fff; --err:#a32020; }
@media (prefers-color-scheme: dark) { :root { --bg:#12161d; --fg:#e8ebf1; --muted:#9aa3b5; --card:#1a2029; --line:#2c3441; --brand:#3fb596; --brand-fg:#07140f; --err:#ff8c8c; } }
* { box-sizing:border-box; } body { margin:0; background:var(--bg); color:var(--fg); font:16px/1.5 system-ui,sans-serif; }
header, main { max-width:880px; margin:0 auto; padding:16px; } header { display:flex; justify-content:space-between; align-items:center; }
header a { color:var(--fg); text-decoration:none; font-weight:600; } nav a { margin-left:16px; font-weight:400; color:var(--muted); }
.grid { display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; }
h1 { font-size:1.6rem; } h2 { font-size:1.2rem; margin-top:0; } .muted { color:var(--muted); font-size:.9rem; }
label { display:block; margin:12px 0 4px; font-weight:600; } input[type=text], input[type=email] { width:100%; padding:10px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--fg); font:inherit; }
.btn { display:inline-block; padding:10px 18px; border-radius:8px; border:0; background:var(--brand); color:var(--brand-fg); font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
.btn.alt { background:transparent; color:var(--fg); border:1px solid var(--line); } code { word-break:break-all; background:var(--bg); padding:2px 6px; border-radius:6px; }
</style>
</head>
<body>
<header><a href="/">Associazione Semplice</a><nav><a href="/free">Licenza gratuita</a><a href="/accedi">Area riservata</a></nav></header>
<main><?php echo $content; ?></main>
</body>
</html>
