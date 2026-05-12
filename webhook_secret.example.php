<?php
/**
 * Copy this file to webhook_secret.local.php and set real values.
 * webhook_secret.local.php is listed in .gitignore.
 *
 * GitHub: Repository → Settings → Webhooks → Add webhook
 *   Payload URL: https://your-domain.example/path/to/webhook.php
 *   Content type: application/json
 *   Secret: use the same string as 'secret' below
 *   Events: Just the push event (or leave "Send everything" if you prefer)
 */
return [
    // Must match the "Secret" field on GitHub's webhook settings.
    'secret' => 'change-me-to-a-long-random-string',

    // Absolute filesystem path to this repo on the server (where .git lives).
    // Example Linux: '/var/www/hotel'
    // Example Windows: 'C:\\inetpub\\wwwroot\\hotel'
    'repo_path' => __DIR__,

    // Only run git pull when this branch is pushed (refs/heads/main, etc.).
    'deploy_branch' => 'main',

    // Optional: set false to verify signature but not run git (for testing).
    'run_git_pull' => true,
];
