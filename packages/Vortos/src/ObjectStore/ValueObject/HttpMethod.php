<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

enum HttpMethod: string
{
    case Get = 'GET';
    case Put = 'PUT';
    case Post = 'POST';
    case Head = 'HEAD';
    case Delete = 'DELETE';
}
