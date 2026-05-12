<?php
/**
 * Copy this file to webhook_secret.local.php and set real values.
 * webhook_secret.local.php is listed in .gitignore.
 *
 * GitHub: Repository → Settings → Webhooks → Add webhook
 *   Payload URL: https://hotel.abdalmenem.com/whookj43.php
 *   Content type: application/json
 *   Secret: use the same string as 'secret' below
 *   Events: Just the push event (or leave "Send everything" if you prefer)
 *
 * CyberPanel: site root is usually public_html. Find the full server path over SSH:
 *   cd ~/hotel.abdalmenem.com/public_html && pwd
 * Typical pattern (replace LINUX_USER with your CyberPanel SSH user):
 *   /home/LINUX_USER/hotel.abdalmenem.com/public_html
 */
return [
    // Must match the "Secret" field on GitHub's webhook settings.
    'secret' => 'change-me-to-a-long-random-string',

    // Absolute path on the server where the .git folder lives (often = public_html).
    // On CyberPanel, __DIR__ works only if webhook_secret.local.php sits next to whookj43.php
    // in public_html; otherwise set the full path from `pwd` above.
    'repo_path' => __DIR__,

    // Only run git pull when this branch is pushed (refs/heads/main, etc.).
    'deploy_branch' => 'main',

    // Optional: set false to verify signature but not run git (for testing).
    'run_git_pull' => true,
];
