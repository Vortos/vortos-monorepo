<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle\StateBackend;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Exception\LocalStateForbiddenException;
use Vortos\Iac\Lifecycle\StateBackend\StateBackendProvider;
use Vortos\Iac\Lifecycle\StateBackend\StateBackendValidator;

final class StateBackendValidatorTest extends TestCase
{
    private StateBackendValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new StateBackendValidator();
    }

    public function test_local_state_for_prod_throws(): void
    {
        $this->expectException(LocalStateForbiddenException::class);
        $this->validator->validate(StateBackendProvider::Local, 'prod');
    }

    public function test_local_state_for_production_throws(): void
    {
        $this->expectException(LocalStateForbiddenException::class);
        $this->validator->validate(StateBackendProvider::Local, 'production');
    }

    public function test_local_state_for_staging_throws(): void
    {
        $this->expectException(LocalStateForbiddenException::class);
        $this->validator->validate(StateBackendProvider::Local, 'staging');
    }

    public function test_local_state_for_dev_is_allowed(): void
    {
        $this->validator->validate(StateBackendProvider::Local, 'dev');
        $this->assertTrue(true);
    }

    public function test_remote_state_for_prod_is_allowed(): void
    {
        $this->validator->validate(StateBackendProvider::S3Dynamodb, 'prod');
        $this->assertTrue(true);
    }

    public function test_gcs_for_staging_is_allowed(): void
    {
        $this->validator->validate(StateBackendProvider::Gcs, 'staging');
        $this->assertTrue(true);
    }
}
