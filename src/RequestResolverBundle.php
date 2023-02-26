<?php

declare(strict_types=1);

namespace GChernikov\RequestResolverBundle;

use GChernikov\RequestResolverBundle\Contract\OperationRequestInterface;
use GChernikov\RequestResolverBundle\DependencyInjection\Compiler\PrepareRequestsCompilerPass;
use GChernikov\RequestResolverBundle\DependencyInjection\RequestResolverExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class RequestResolverBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(OperationRequestInterface::class)
            ->addTag(PrepareRequestsCompilerPass::OPERATION_REQUEST_TAG)
        ;

        $container->addCompilerPass(new PrepareRequestsCompilerPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new RequestResolverExtension();
    }
}
