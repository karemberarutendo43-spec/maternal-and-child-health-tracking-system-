<?php

// Google OAuth2 / OpenID Connect settings.
// Do NOT commit real secrets. Use environment variables in production.

define('MCH_GOOGLE_OAUTH_CLIENT_ID', getenv('MCH_GOOGLE_OAUTH_CLIENT_ID') ?: '');
define('MCH_GOOGLE_OAUTH_CLIENT_SECRET', getenv('MCH_GOOGLE_OAUTH_CLIENT_SECRET') ?: '');

// Redirect URI registered in Google Cloud Console.
// For localhost development:
//   http://localhost/mch_system/auth/oauth_callback.php?provider=google
define('MCH_GOOGLE_OAUTH_REDIRECT_URI', getenv('MCH_GOOGLE_OAUTH_REDIRECT_URI') ?: 'http://localhost/mch_system/auth/oauth_callback.php?provider=google');

// Optional: force hosted domain (Google Workspace). Leave blank for public Gmail.
define('MCH_GOOGLE_OAUTH_HD', getenv('MCH_GOOGLE_OAUTH_HD') ?: '');

