<?php
declare(strict_types=1);
// Legacy URL retained for bookmarks; registration now uses email verification.
header('Location: register.php', true, 302);
exit;
