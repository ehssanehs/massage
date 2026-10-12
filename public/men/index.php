<?php
declare(strict_types=1);

/**
 * Men's section entry point.
 *
 * This is a real file on disk, so /men/index.php keeps working on servers that
 * cannot rewrite URLs at all (nginx without try_files, Apache with
 * AllowOverride None) — the rewritten route is only needed for the bare /men
 * form. The front controller reads the section from the request path, so no
 * state is pinned here.
 */
require __DIR__ . '/../index.php';
