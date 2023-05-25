<?php

declare(strict_types=1);

namespace GChernikov\RequestResolverBundle\ArgumentResolver;

use GChernikov\RequestResolverBundle\ArgumentResolver\RequestResolver\RequestResolver;
use ReflectionException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

final class RequestArgumentResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly RequestResolver $requestResolver,
    ) {
    }

    /**
     * @inheritDoc
     * @throws ReflectionException
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (
            $argument->getType() === null
            || $argument->isVariadic()
            || !class_exists($argument->getType())
            || !$this->requestResolver->supports($argument->getType())
        ) {
            return [];
        }

        return [$this->requestResolver->resolve($request, $argument->getType())];
    }
}
