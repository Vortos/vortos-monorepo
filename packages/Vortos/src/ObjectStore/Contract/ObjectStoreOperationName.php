<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

enum ObjectStoreOperationName: string
{
    case Put = 'put';
    case Get = 'get';
    case Stream = 'stream';
    case Head = 'head';
    case Exists = 'exists';
    case Delete = 'delete';
    case DeleteMany = 'delete_many';
    case Copy = 'copy';
    case Move = 'move';
    case List = 'list';
    case TemporaryDownloadUrl = 'temporary_download_url';
    case TemporaryUploadUrl = 'temporary_upload_url';
    case TemporaryPostUpload = 'temporary_post_upload';
    case CreateUploadIntent = 'create_upload_intent';
    case Promote = 'promote';
    case Abort = 'abort';
    case OutboxWrite = 'outbox_write';
    case OutboxRelay = 'outbox_relay';
    case MultipartUpload = 'multipart_upload';
    case LifecyclePlan = 'lifecycle_plan';
    case LifecycleApply = 'lifecycle_apply';
    case LifecycleRemove = 'lifecycle_remove';
}
