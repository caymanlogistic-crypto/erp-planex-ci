<?php
/** Presentation state is keyed to the current request's authorized identity. */
$registryUiStateScope = \App\Security\RegistryUiStateScope::fingerprint(
    (int) ($_SESSION['account_id'] ?? 0),
    \App\Security\AccessRuntime::current(),
);
