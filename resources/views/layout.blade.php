<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Profiles') · Profile Refresher</title>
    <style>
        :root { --fg: #1d1d1f; --muted: #6e6e73; --line: #e3e3e8; --bg: #fafafa; --card: #fff; --ok: #1a7f37; --warn: #9a6700; --bad: #cf222e; --accent: #0969da; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--fg); background: var(--bg); }
        header { display: flex; gap: 16px; align-items: center; padding: 12px 24px; background: var(--card); border-bottom: 1px solid var(--line); }
        header a { color: var(--fg); text-decoration: none; font-weight: 600; }
        header nav { margin-left: auto; display: flex; gap: 16px; }
        header nav a { font-weight: 400; color: var(--accent); }
        main { max-width: 1200px; margin: 0 auto; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 16px; }
        h2 { font-size: 15px; margin: 24px 0 8px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: 16px; }
        table { width: 100%; border-collapse: collapse; background: var(--card); }
        th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { font-size: 12px; color: var(--muted); font-weight: 600; white-space: nowrap; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: var(--muted); }
        .badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 12px; border: 1px solid currentColor; white-space: nowrap; }
        .ok { color: var(--ok); } .warn { color: var(--warn); } .bad { color: var(--bad); }
        form.inline { display: inline-flex; gap: 8px; }
        input[type=text] { padding: 6px 8px; border: 1px solid var(--line); border-radius: 6px; min-width: 220px; }
        button { padding: 6px 12px; border: 1px solid var(--accent); background: var(--accent); color: #fff; border-radius: 6px; cursor: pointer; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 16px; justify-content: space-between; margin-bottom: 16px; }
        .flash { padding: 8px 12px; border-radius: 6px; background: #ddf4ff; margin-bottom: 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
        .stat b { display: block; font-size: 18px; font-variant-numeric: tabular-nums; }
        pre { white-space: pre-wrap; word-break: break-all; font-size: 12px; max-height: 480px; overflow: auto; background: #f6f8fa; padding: 12px; border-radius: 6px; }
        .wrap { overflow-x: auto; }
        a { color: var(--accent); }
    </style>
</head>
<body>
<header>
    <a href="{{ route('profiles.index') }}">Profile Refresher</a>
    <nav>
        <a href="{{ route('profiles.index') }}">Profiles</a>
        <a href="/horizon">Horizon</a>
    </nav>
</header>
<main>
    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif
    @yield('content')
</main>
</body>
</html>
