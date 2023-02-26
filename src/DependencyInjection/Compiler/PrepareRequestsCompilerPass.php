<?php

declare(strict_types=1);

namespace GChernikov\RequestResolverBundle\DependencyInjection\Compiler;

use Exception;
use OpenApi\Annotations\Operation;
use OpenApi\Attributes\Parameter;
use OpenApi\Generator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @template T
 */
class PrepareRequestsCompilerPass implements CompilerPassInterface
{
    public const OPERATION_REQUEST_TAG = 'app.operation.request';

    /**
     * @inheritDoc
     * @throws ReflectionException
     */
    public function process(ContainerBuilder $container): void
    {
        $targetResolverDefinition = $container
            ->getDefinition('gchernikov_request_resolver.request_resolver');

        $handleableRequests = array_keys($container->findTaggedServiceIds(self::OPERATION_REQUEST_TAG));

        if (!count($handleableRequests)) {
            return;
        }

        $controllerIDs = array_keys(
            $container->findTaggedServiceIds('controller.service_arguments'),
        );

        if (!count($controllerIDs)) {
            return;
        }

        $targetResolverDefinition->setArgument(
            key: '$supportedRequests',
            value: $this->getRequestsResolverConfiguration(
                $controllerIDs,
                $handleableRequests,
            ),
        );
    }

    /**
     * @param array<class-string> $controllerIDs
     * @param array<class-string> $handleableRequests
     * @return array<class-string, array<string, array<string, string>>>
     * @throws ReflectionException
     * @throws Exception
     */
    private function getRequestsResolverConfiguration(
        array $controllerIDs,
        array $handleableRequests,
    ): array {
        $controllers = $this->getCompatibleControllers($controllerIDs, $handleableRequests);
        $requestsMap = [];

        foreach ($controllers as [$controllerReflection, $controllerRoute, $methods]) {
            foreach ($methods as [$methodReflection, $methodRoute, $supportedRequests]) {
                $routeName = $methodRoute?->getName() ?? $controllerRoute?->getName();

                if (!$routeName) {
                    continue;
                }

                foreach ($supportedRequests as $messageClass) {
                    $requestsMap[$messageClass][$routeName] = array_merge(
                        $this->getOpenApiParametersInOptions($controllerReflection),
                        $this->getOpenApiParametersInOptions($methodReflection),
                    );
                }
            }
        }

        return $requestsMap;
    }

    /**
     * @param array<class-string> $controllerIDs
     * @param array<class-string> $handleableRequests
     * @return array<array{0:ReflectionClass, 1:Route|null, 2:array<array{0:ReflectionMethod, 1:Route|null, 2:array<class-string>}>}>
     * @throws ReflectionException
     */
    private function getCompatibleControllers(array $controllerIDs, array $handleableRequests): array
    {
        $results = [];
        $controllerIDs = array_filter(
            $controllerIDs,
            static fn (string $id): bool => str_contains(
                haystack: $id,
                needle: 'App\\Controller',
            )
        );

        foreach ($controllerIDs as $controllerID) {
            $reflect = new ReflectionClass($controllerID);
            $methods = $this->getCompatibleMethods(
                controller: $reflect,
                handleableRequests: $handleableRequests,
            );

            $methods && ($results[] = [$reflect, $this->getRouteAttribute($reflect), $methods]);
        }

        return $results;
    }

    /**
     * @param array<class-string> $handleableRequests
     * @return array<array{0:ReflectionMethod, 1:Route|null, 2:array<class-string>}>
     */
    private function getCompatibleMethods(ReflectionClass $controller, array $handleableRequests): array
    {
        $results = [];
        $methods = array_filter(
            array: $controller->getMethods(filter: ReflectionMethod::IS_PUBLIC),
            callback: static fn (ReflectionMethod $method): bool
                => !$method->isAbstract()
                && !$method->isConstructor()
                && !$method->isDestructor(),
        );

        foreach ($methods as $method) {
            $supportedRequests = [];

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $type instanceof ReflectionNamedType &&
                $name = $type->getName();

                isset($name) && in_array($name, $handleableRequests, strict: true) && (
                    $supportedRequests[] = $name
                );
            }

            $supportedRequests && (
                $results[] = [$method, $this->getRouteAttribute($method), $supportedRequests]
            );
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    private function getOpenApiParametersInOptions(ReflectionClass|ReflectionMethod $methodOrClass): array
    {
        return array_column([
            ...array_map(
                $this->getParameterMapping(...),
                $this->getParameterAttributeInstances($methodOrClass),
            ),
            ...array_map(
                $this->getParameterMapping(...),
                $this->getOperationParameterInstances($methodOrClass),
            ),
        ], column_key: 1, index_key: 0);
    }

    private function getRouteAttribute(ReflectionClass|ReflectionMethod $reflection): ?Route
    {
        $attribs = $reflection->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);
        $attribute = current($attribs);
        $attribute instanceof ReflectionAttribute && ($route = $attribute->newInstance());

        return $route ?? null;
    }

    /**
     * @return array<Parameter>
     */
    private function getOperationParameterInstances(
        ReflectionClass|ReflectionMethod $methodOrClass,
    ): array {
        $operation = $methodOrClass->getAttributes(
            Operation::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        $operation = array_pop($operation)?->newInstance();

        return $operation instanceof Operation && $operation->parameters !== Generator::UNDEFINED
            ? $operation->parameters
            : [];
    }

    /**
     * @return array<Parameter>
     */
    private function getParameterAttributeInstances(
        ReflectionClass|ReflectionMethod $methodOrClass,
    ): array {
        return array_map(
            static fn (ReflectionAttribute $attr): Parameter => $attr->newInstance(),
            $methodOrClass->getAttributes(Parameter::class, ReflectionAttribute::IS_INSTANCEOF),
        );
    }

    /**
     * @return array{0?:string, 1?:string}|array<string, string>
     */
    private function getParameterMapping(Parameter $parameter): array
    {
        if ($parameter->in !== Generator::UNDEFINED) {
            return [$parameter->name, $parameter->in];
        }

        return [];
    }
}
