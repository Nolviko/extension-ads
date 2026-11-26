# Ads for Nolviko

Official [Nolviko](https://github.com/Nolviko/nolviko) extension: ads in fixed places of the forum, managed from
**Admin → Extensions → Ads** without editing templates.

## Features

- **Places:** below the top menu, end of the page content, between forums on the board index, between threads on
  a forum page, between posts (after post N or every N posts), after the last post, and the page head (AdSense Auto
  ads or a verification snippet).
- **Ad types:** ad code from any network (AdSense, Ad Manager, ...), an image with a link, or a text ad. Script
  tags in ad code get the page's CSP nonce automatically.
- **Rules:** audience (everyone, guests, members), device (all, phones, computers), first and last day, weight for
  rotation when several ads share a place. Groups with the permission `nolviko.ads.hide` never see ads.
- **Policy-safe by default:** no ads on sign-in, registration, account, private messages, moderation or admin
  pages (AdSense does not allow ads on screens without content), plus your own excluded addresses; an optional
  "Advertisement" label; image and text ads use `rel="sponsored"`.
- **ads.txt** editor, served at `/ads.txt`.
- **Google AdSense option** adds Google's ad hosts to the Content-Security-Policy; other networks' hosts can be
  added one per line. Only `https://` hosts are accepted.
- Click counts per ad (image and text ads; crawlers are not counted). The click route only follows the saved link
  of a saved ad, so it is never an open redirect.

Ads are read from one small file, so showing them costs no database query; guest pages stay in Nolviko's render
cache (rotation changes at most once a minute for guests).

## Installation

1. Download `nolviko-ext-nolviko-ads-<version>.zip` and copy its SHA-256.
2. **Admin → Extensions → Upload**, choose the ZIP and confirm. Enable it.

Requires Nolviko with extension API 1.4 or newer.

## Development

```bash
php <nolviko-dev-vendor>/bin/phpunit --bootstrap <nolviko-dev-vendor>/autoload.php tests
```

## License

[Apache License 2.0](LICENSE), like Nolviko itself.
