<?php

use Vortos\Auth\DependencyInjection\VortosAuthConfig;

return static function (VortosAuthConfig $config): void {
    $config
        ->secret($_ENV['JWT_SECRET'] ?: throw new \RuntimeException('JWT_SECRET not set'))
        ->accessTokenTtl(900)
        ->refreshTokenTtl(604800)
        ->issuer($_ENV['APP_NAME'] ?: 'squaura');
};
