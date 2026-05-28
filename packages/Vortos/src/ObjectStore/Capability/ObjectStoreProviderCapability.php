<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Capability;

enum ObjectStoreProviderCapability: string
{
    case BasicObjectOperations = 'basic_object_operations';
    case PresignedUrls = 'presigned_urls';
    case PostPolicyUploads = 'post_policy_uploads';
    case MultipartUploads = 'multipart_uploads';
    case PublicUrls = 'public_urls';
    case LifecycleConfiguration = 'lifecycle_configuration';
    case LifecyclePrefixExpiration = 'lifecycle_prefix_expiration';
    case ObjectAcl = 'object_acl';
    case ObjectLock = 'object_lock';
    case ObjectTagging = 'object_tagging';
    case KmsEncryption = 'kms_encryption';
}
