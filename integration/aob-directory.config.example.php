<?php
/**
 * Host-specific settings for aob-directory.php.
 *
 * Copy to public_html/directorycore/aob-directory.config.php on the host and
 * fill in. Not deployed by CI (it is not in the deploy branch), so it survives
 * every release; edit it on the host to point a page at UAT during testing.
 */
return [
    // API origin the app calls. UAT: https://aob-uat.techneosis.dev
    'api_base' => 'https://portal.assemblyofbishops.org',
    // The site's Google Maps browser key (referrer-restricted to the site).
    // Same key the legacy directorycore templates embed.
    'maps_key' => '',
];
