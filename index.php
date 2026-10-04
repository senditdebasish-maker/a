<?php

declare(strict_types=1);

/**
 * Root front controller for deployments where the project contents are copied
 * directly into XAMPP's htdocs directory. The application code and private
 * data remain outside public/ and continue to use the existing bootstrap.
 */
require __DIR__ . '/public/index.php';
