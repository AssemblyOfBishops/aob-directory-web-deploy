<?php
/**
 * Mounts the React directory app into a directorycore page.
 *
 * Lives at public_html/directorycore/aob-directory.php on www (deployed from
 * this repo's `deploy` branch alongside app/). It resolves the hashed
 * filenames out of Vite's manifest, so shipping a new build never requires
 * editing a template or a MODX document.
 *
 * Two ways to use it:
 *
 *   1. From a MODX Evolution snippet (the www site). The snippet body is:
 *
 *        include_once MODX_BASE_PATH . 'directorycore/aob-directory.php';
 *        return aob_directory_markup($directory ?? 'parishes')
 *            ?? $modx->runSnippet($fallback ?? 'ParishDirectory');
 *
 *      and the document calls it as
 *        [!AobDirectory? &directory=`parishes` &fallback=`ParishDirectory`!]
 *
 *   2. From a plain PHP template:
 *
 *        <?php require_once __DIR__ . '/../directorycore/aob-directory.php'; ?>
 *        <?php if (!aob_directory_render('parishes')): ?>
 *            ... existing legacy markup, unchanged ...
 *        <?php endif; ?>
 *
 * Both return the "use the legacy directory" signal (null / false) when
 * ?legacy=1 is present, or the manifest is missing or unreadable, so the old
 * directory keeps serving rather than leaving the page empty. That is the
 * rollback path: no deploy required.
 *
 * Environment (API origin, Maps key) comes from aob-directory.config.php next
 * to this file, which is NOT in the repo -- it is created once on the host.
 */

const AOB_DIRECTORY_ASSET_BASE = '/directorycore/app';
const AOB_DIRECTORY_MANIFEST = __DIR__ . '/app/.vite/manifest.json';
const AOB_DIRECTORY_ENTRY = 'src/main.tsx';
const AOB_DIRECTORY_CONFIG = __DIR__ . '/aob-directory.config.php';

/** Defaults; aob-directory.config.php overrides by returning an array. */
function aob_directory_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $config = [
        'api_base' => 'https://portal.assemblyofbishops.org',
        'maps_key' => '',
    ];
    if (is_readable(AOB_DIRECTORY_CONFIG)) {
        $override = include AOB_DIRECTORY_CONFIG;
        if (is_array($override)) {
            $config = array_merge($config, $override);
        }
    }
    return $config;
}

function aob_directory_manifest(): ?array
{
    static $manifest = null;
    if ($manifest !== null) {
        return $manifest ?: null;
    }

    if (!is_readable(AOB_DIRECTORY_MANIFEST)) {
        error_log('aob-directory: manifest not readable at ' . AOB_DIRECTORY_MANIFEST);
        $manifest = false;
        return null;
    }

    $decoded = json_decode((string) file_get_contents(AOB_DIRECTORY_MANIFEST), true);
    if (!is_array($decoded) || !isset($decoded[AOB_DIRECTORY_ENTRY]['file'])) {
        error_log('aob-directory: manifest missing entry ' . AOB_DIRECTORY_ENTRY);
        $manifest = false;
        return null;
    }

    $manifest = $decoded;
    return $manifest;
}

/**
 * The markup for one directory, or null when the legacy page should render.
 *
 * @param string $directory 'parishes' or 'mental-health'
 * @param string|null $apiBase override the configured API origin (e.g. UAT on a beta page)
 */
function aob_directory_markup(string $directory, ?string $apiBase = null): ?string
{
    // Explicit opt-out, so a problem in production is one query parameter away
    // from the old directory for anyone who needs it.
    if (isset($_GET['legacy']) && $_GET['legacy'] === '1') {
        return null;
    }

    $manifest = aob_directory_manifest();
    if ($manifest === null) {
        return null;
    }

    // MODX sends every page with max-age=3600, and this page's HTML names a
    // hashed bundle -- so for an hour after a deploy, returning browsers keep
    // running the previous build. Make the mounting page revalidate instead;
    // the assets themselves stay long-cached under their hashed names.
    if (!headers_sent()) {
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    $config = aob_directory_config();
    $entry = $manifest[AOB_DIRECTORY_ENTRY];
    $script = AOB_DIRECTORY_ASSET_BASE . '/' . $entry['file'];
    $styles = $entry['css'] ?? [];
    $api = $apiBase ?: $config['api_base'];

    $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $html = '';
    foreach ($styles as $stylesheet) {
        $html .= sprintf(
            '<link rel="stylesheet" href="%s">' . PHP_EOL,
            $esc(AOB_DIRECTORY_ASSET_BASE . '/' . $stylesheet)
        );
    }

    // The inline min-height is not decoration: the node is empty until the
    // bundle runs, and without reserved space the whole page jumps when the
    // directory appears (measured as 0.74 cumulative layout shift). It matches
    // the app's own minimum layout height.
    $html .= sprintf(
        '<div id="aob-directory-root" data-directory="%s" data-api-base="%s" data-maps-key="%s"'
        . ' style="min-height:min(78vh,820px)"></div>' . PHP_EOL,
        $esc($directory),
        $esc($api),
        $esc((string) $config['maps_key'])
    );

    // type="module" is the browser support line: anything that cannot run it
    // ignores the tag entirely, which is why the noscript fallback below
    // points at the legacy page rather than showing nothing.
    $html .= sprintf('<script type="module" src="%s"></script>' . PHP_EOL, $esc($script));

    $html .= '<noscript><p>This directory needs JavaScript. '
        . '<a href="?legacy=1">Use the previous directory</a>.</p></noscript>' . PHP_EOL;

    return $html;
}

/**
 * Print-style variant for plain PHP templates.
 *
 * @return bool true when the app was mounted; false means render the legacy markup.
 */
function aob_directory_render(string $directory, ?string $apiBase = null): bool
{
    $html = aob_directory_markup($directory, $apiBase);
    if ($html === null) {
        return false;
    }
    echo $html;
    return true;
}
