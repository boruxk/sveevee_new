<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($status === 404)<meta name="robots" content="noindex,follow">@endif
    <title>{{ $title }} | Sveevee</title>
</head>
<body>
    <main>
        <h1>{{ $title }}</h1>
        <a href="/">Sveevee</a>
    </main>
</body>
</html>
