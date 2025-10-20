# Auto Translate (TT-RSS plugin)

This plugin adds automatic article translation to [Tiny Tiny RSS](https://tt-rss.org/) using any LibreTranslate-compatible REST API (e.g. LibreTranslate, Argos Translate, TranslateLocally). It can optionally add translated titles and replace original content inline, providing a smoother reading experience for multilingual feeds.

## Features

- **Automatic translation**: Translate article bodies on view, or request translation manually.
- **Title translation**: Extend translation to article titles with selectable display (append or replace).
- **Flexible display**: Choose to append translations under the original text or replace the article content entirely.
- **LibreTranslate-compatible**: Works with self-hosted instances (recommended) or public endpoints.
- **Language detection aware**: Skips translation if the detected language already matches your target.

## Requirements

- TT-RSS 2024.XX or newer (Plugin API v2).
- PHP with curl/Guzzle (available in standard TT-RSS containers).
- A LibreTranslate-compatible service reachable from the TT-RSS backend (examples below).

## Installation

1. Clone or drop this plugin into `plugins.local/auto_translate/`.
2. Enable the plugin:
   - For system-wide usage, add `auto_translate` to the `TTRSS_PLUGINS` environment variable (e.g. in `.env`) or the advanced configuration as a system plugin.
   - For per-user usage, enable it in Preferences → Plugins.
3. Restart the TT-RSS containers if running under Docker so the environment variable change is loaded:

   ```bash
   docker compose restart app updater web-nginx
   ```

## Configuration

Open Preferences → Preferences → **Article auto-translation** to configure:

| Option | Description |
| --- | --- |
| **Service URL** | Base URL of your LibreTranslate-compatible API (e.g. `http://translate`). |
| **API key** | Optional API key for services requiring authentication. |
| **Target language** | ISO-639-1 code of your preferred language (e.g. `en`, `de`). |
| **Mode** | `Translate automatically and append` or `Translate manually`. |
| **Display translated content** | Append translation below original content, or replace the article content inline. |
| **Translate article titles** | Toggle title translation. |
| **Display translated titles** | Append translated titles under the original or replace the headline text (with a translate icon indicator). |

### Example: Self-hosted LibreTranslate via Docker Compose

```yaml
translate:
  image: libretranslate/libretranslate:latest
  restart: unless-stopped
  environment:
    LT_LOAD_ONLY: "en,es,fr,de,nl,ru"
    LT_UPDATE_MODELS: "true"
    LT_HOST: "0.0.0.0"
    LT_PORT: "80"
  ports:
    - "5000:80"
  volumes:
    - argos_translate_data:/root/.local/share/argos-translate
```

Point the plugin at `http://translate` inside the Docker network (default). For host access, use `http://localhost:5000`.

### Using the Docker Compose snippet

- **Add the service**: Drop the snippet above into the `services:` section of your main `docker-compose.yml` alongside the TT-RSS containers. Keep the `argos_translate_data` named volume (defined at the bottom) so downloaded models persist across restarts.
- **Match your networks**: If your stack defines custom networks, attach the `translate` service to the same network as the TT-RSS `app` container. Otherwise the default network is sufficient and no extra configuration is required.
- **Expose or hide the API**: The service maps to port `80` internally and `5000` externally by default. Override the public port with `TRANSLATE_PORT=<port>` in your `.env` file (e.g. `TRANSLATE_PORT=5555`) or remove the `ports` entry if you only want in-cluster access.
- **Start the translator**: Run `docker compose up -d translate` (or `docker-compose up -d translate`) from your stack directory. First boot downloads language models to the named volume and can take several minutes; track progress with `docker compose logs -f translate`.
- **Wire it into the plugin**: Once the container reports `Server is running` in the logs, set the plugin's **Service URL** to `http://translate` (or `http://localhost:<port>` if you kept the public mapping) and save the preferences.

## Usage Notes

- Translation requests happen on-demand per article; results are cached client-side during the session.
- A subtle translate icon (`material-icons`) marks translated sections and titles. Tooltips show language hints.
- The plugin sanitizes translation responses and removes `SC_ON`/`SC_OFF` markers returned by some services.
- Manual translation is available via the article toolbar button when automatic mode is disabled.

## Troubleshooting

- **“Requested URL failed extended validation.”** Ensure the translation service listens on port 80 or 443; TT-RSS blocks non-standard ports for security reasons. Map your container to host port 5000 but keep the internal service on 80.
- **401/403 errors**: Supply the API key if your service requires one.
- **Translation skipped**: The detected input language already matches your target; adjust the target language or disable detection by setting `source` to your desired language in the plugin code.
- **Service unreachable**: Verify network connectivity from the TT-RSS backend container (`docker compose exec app curl http://translate/languages` with curl installed).

## License

MIT License © 2024 — see repository root if bundled.

## Contributors

- Original implementation: Derek Linz
